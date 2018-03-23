<?php

namespace MakinaCorpus\FilechunkBundle\Controller;

use MakinaCorpus\FilechunkBundle\File\FileBuilder;
use MakinaCorpus\FilechunkBundle\Form\Type\FilechunkType;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\MimeType\MimeTypeGuesser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class UploadController extends Controller
{
    /**
     * Translate message
     *
     * @param string $message
     * @param array $args
     *
     * @return string
     */
    private function translate($message, $args = [])
    {
        return $this->get('translator')->trans($message, $args);
    }

    /**
     * Get upload directory.
     *
     * @return string
     */
    private function getUploadDirectory()
    {
        if ($this->container->hasParameter('filechunk.upload_directory')) {

            $directory = $this->container->getParameter('filechunk.upload_directory');

            if ($directory) {
                if (!is_dir($directory)) {
                    throw new IOException(sprintf("%s: not a directory", $directory));
                }
                if (!is_writable($directory)) {
                    throw new IOException(sprintf("%s: is not writable", $directory));
                }

                return $directory;
            }
        }

        return sys_get_temp_dir() . '/filechunk';
    }

    /**
     * Build or fetch the token, associated to the session
     *
     * @return string
     */
    private function getCurrentToken()
    {
        /** @var \Symfony\Component\HttpFoundation\Session\SessionInterface $session */
        $session = $this->get('session');
        $token = $session->get(FilechunkType::SESSION_TOKEN);

        if (!$token) {
            // @todo find something better
            $token = base64_encode(mt_rand() . mt_rand() . mt_rand());
            $session->set(FilechunkType::SESSION_TOKEN, $token);
        }

        return $token;
    }

    /**
     * Parse content range header
     *
     * @param string $contentRange
     *   Content-Range header value
     *
     * @return int[]
     *   First value is range start, second is range stop, third is file size
     */
    private function parseRange($contentRange)
    {
        $matches = [];
        if (!preg_match('@^bytes (\d+)-(\d+)/(\d+)$@', trim($contentRange), $matches)) {
            throw $this->createAccessDeniedException(); // Invalid header
        }

        list(, $start, $stop, $filesize) = $matches;

        // Check everyone are positive integers
        if ($filesize < 0) {
            throw $this->createAccessDeniedException(); // Invalid request.
        }
        if ($start < 0) {
            throw $this->createAccessDeniedException(); // Invalid request.
        }
        if ($stop < 0) {
            throw $this->createAccessDeniedException(); // Invalid request.
        }

        // Check that values are coherent alltogether
        if ($filesize < $start) {
            throw $this->createAccessDeniedException(); // Invalid request.
        }
        if ($filesize < $stop) {
            throw $this->createAccessDeniedException(); // Invalid request.
        }
        if ($stop < $start) {
            throw $this->createAccessDeniedException(); // Invalid request.
        }
        if ($start === $stop) {
            throw $this->createAccessDeniedException(); // Cannot import '0' sized file.
        }

        return [(int)$start, (int)$stop, (int)$filesize];
    }

    /**
     * File chunk remove
     */
    public function removeAction(Request $request)
    {
        if (!$request->isMethod('POST')) {
            throw $this->createAccessDeniedException();
        }

        // Filename is required
        $fileId = $request->headers->get('X-File-Id');
        if (empty($fileId)) {
            throw $this->createAccessDeniedException();
        }

        // Token is optional, but if set must be valid
        $token = $request->headers->get('X-File-Token');
        if ($token && false /* is valid token */) {
            throw $this->createAccessDeniedException();
        }

        // @todo Really remove the file, I do need to explore a few things
        // $fileSystem = new Filesystem();
        // $uploadDirectory = $this->getUploadDirectory();

        return new JsonResponse(['remove' => true]);
    }

    /**
     * File chnk upload
     */
    public function uploadAction(Request $request)
    {
        // Already checked by router, still better be safe than sorry
        if (!$request->isMethod('POST')) {
            throw $this->createAccessDeniedException();
        }

        // Filename is required
        $filename = $request->headers->get('X-File-Name');
        if (empty($filename)) {
            throw $this->createAccessDeniedException();
        }
        // File name might be encoded in base64 to avoid encoding errors
        if ('==' === substr($filename, -2) || (false === strpos($filename, '.') && preg_match('#^[a-zA-Z0-9\+/]+={0,2}$#ims', $filename))) {
            $filename = base64_decode($filename);
        }
        // JavaScript widget will use encodeURIComponent() in which space char is
        // encoded using %20 and not +, so we are safe to use rawurldecode() and not
        // urldecode() here.
        $filename = rawurldecode($filename);

        // Token is optional, but if set must be valid
        $token = $request->headers->get('X-File-Token');
        if ($token && false /* is valid token */) {
            throw $this->createAccessDeniedException();
        }

        // Parse content size, range, and other details
        $rawRange = $request->headers->get('Content-Range');
        list($start, $stop, $filesize) = $this->parseRange($rawRange);
        $length = $stop - $start;

        $realContentLength = (int)$request->headers->get('Content-Length');
        if ($realContentLength !== $length) {
            throw $this->createAccessDeniedException();
        }

        // Proceed with incomming file validation
        if ($token) {
            /** @var \Symfony\Component\HttpFoundation\Session\SessionInterface $session */
            $session = $this->get('session');
            if (!$session->has('filechunk_' . $token)) {
                throw $this->createAccessDeniedException(); // Actual CSRF
            }
            $options = $session->get('filechunk_' . $token);

            // Validation basics
            if (!empty($options['maxSize']) && $options['maxSize'] < $filesize) {
                return new JsonResponse(['message' => $this->translate("Maximum file size allowed is @bytes bytes", ['@bytes' => $options['maxSize']])], 403);
            }
// @todo This actually needs files to exists to guess
//   and symfony does not implements a file extension mime type guesser
//             if (!empty($options['mimeTypes'])) {
//                 $allowed = $options['mimeTypes'];
//                 if (!is_array($allowed)) {
//                     $allowed = [$allowed];
//                 }
//                 $guesser = MimeTypeGuesser::getInstance();
//                 $mimeType = $guesser->guess($filename);
//                 if (!in_array($mimeType, $allowed)) {
//                     return new JsonResponse(['message' => $this->translate("Allowed mime types are @mimes", ['@mimes' => implode(', ', $allowed)])], 403);
//                 }
//             }
        }

        /** @var \MakinaCorpus\FilechunkBundle\File\FileBuilder $builder */
        $builder    = null;
        $isComplete = false;
        $file       = null;

        try {
            $input = fopen("php://input", "rb");
            if (!$input) {
                throw new \RuntimeException("Could not open HTTP POST input stream");
            }

            // @todo get user identifier if possible
            $builder    = new FileBuilder($filesize, $filename, null, $this->getUploadDirectory() . '/' . $token);
            $written    = $builder->write($input, $start, $length);
            $file       = $builder->getFile();
            $isComplete = $builder->isComplete();

        } finally {
            if ($input) {
                @fclose($input);
            }
        }

        return new JsonResponse([
            'finished'  => $isComplete,
            'offset'    => $builder->getOffset(),
            'preview'   => $file->getFilename(),
            'fid'       => $file->getFilename(),
            'writen'    => $written,
            'hash'      => $isComplete ? md5_file($builder->getAbsolutePath()) : null,
            'mimetype'  => $file->getMimeType(),
            'filename'  => $file->getFilename(),
        ]);
    }

    public function deleteAction(Request $request)
    {
        throw new \Exception("Not implemented yet");
    }
}
