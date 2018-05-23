<?php

namespace MakinaCorpus\FilechunkBundle\Controller;

use MakinaCorpus\FilechunkBundle\File\FileBuilder;
use MakinaCorpus\FilechunkBundle\FileSessionHandler;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
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

        // This method could be much more concise, but I want to keep every
        // condition readable, as they are all very important. Any broken or
        // missing data means that someone is messing up with the front side
        // of things and is a potential security breach attempt. In the less
        // pessimistic side of things, any broken data here would then lead
        // to a probably broken stored file, nobody want that.

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

        // @todo validate token using session handler
        $token = $request->headers->get('X-File-Token');
        if ($token && false /* is valid token */) {
            throw $this->createAccessDeniedException();
        }

        // FIXME: FILE NEEDS TO BE DELETED! need field name for this

        // @todo Really remove the file, I do need to explore a few things
        // $fileSystem = new Filesystem();
        // $uploadDirectory = $this->getUploadDirectory();

        return new JsonResponse(['remove' => true]);
    }

    /**
     * Validate uploaded file using session token.
     */
    private function validateUploadedFile(FileSessionHandler $sessionHandler, Request $request, string $token, string $fieldname = '', int $filesize = 0)
    {
        if (!$sessionHandler->isTokenValid($token)) {
            throw $this->createAccessDeniedException();
        }

        // X-File-Field was added recently, in order to avoid API breakage, it
        // remains optional, older JS still have versions will have nasty bugs.
        if ($fieldname) {
            if (!$options = $sessionHandler->getFieldConfig($fieldname)) {
                throw $this->createAccessDeniedException();
            }

            // @todo should be modved out to another class, this is not the
            //    controller responsability to do this.
            if ($filesize && !empty($options['maxSize']) && $options['maxSize'] < $filesize) {
                return $this->translate("Maximum file size allowed is @mega mo", ['@mega' => round($options['maxSize'] / 1024 / 1024, 1)]);
            }
        }

        // Validation basics
// @todo This actually needs files to exists to guess
//   and symfony does not implements a file extension mime type guesser:
//       Symfony\Component\HttpFoundation\File\MimeType\MimeTypeGuesser
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

    /**
     * Upload endpoint.
     */
    public function uploadAction(Request $request)
    {
        /** @var \MakinaCorpus\FilechunkBundle\FileSessionHandler $sessionHandler */
        $sessionHandler = $this->get('filechunk.session_handler');

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

        // Parse content size, range, and other details
        $rawRange = $request->headers->get('Content-Range');
        list($start, $stop, $filesize) = $this->parseRange($rawRange);
        $length = $stop - $start;

        // Validate current chunk size against Content-Length header.
        $realContentLength = (int)$request->headers->get('Content-Length');
        if ($realContentLength !== $length) {
            throw $this->createAccessDeniedException();
        }

        // Proceed with incoming file validation
        $token = $request->headers->get('X-File-Token', '');
        $fieldname = $request->headers->get('X-File-Field', '');
        if ($message = $this->validateUploadedFile($sessionHandler, $request, $token, $fieldname, $filesize)) {
            return $this->json(['message' => $message], 403);
        }

        $file = null; $builder = null; $isComplete = false;
        try {
            $input = fopen("php://input", "rb");
            if (!$input) {
                throw new \RuntimeException("Could not open HTTP POST input stream");
            }
            // @todo get user identifier if possible
            $builder    = new FileBuilder($filesize, $filename, $sessionHandler->getTemporaryFilePath($fieldname));
            $written    = $builder->write($input, $start, $length);
            $file       = $builder->getFile();
            $isComplete = $builder->isComplete();
        } finally {
            if ($input) {
                @fclose($input);
            }
        }

        return $this->json([
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
