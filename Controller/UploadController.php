<?php

declare(strict_types=1);

namespace MakinaCorpus\FilechunkBundle\Controller;

use MakinaCorpus\FilechunkBundle\FieldConfig;
use MakinaCorpus\FilechunkBundle\FileEvent;
use MakinaCorpus\FilechunkBundle\FileSessionHandler;
use MakinaCorpus\FilechunkBundle\File\FileBuilder;
use MakinaCorpus\Files\FileManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\MimeTypes;
use Symfony\Component\Validator\Constraints\File as FileConstraint;

final class UploadController extends AbstractController
{
    /**
     * Translate message
     */
    private function translate(string $message, array $args = []): string
    {
        try {
            return $this->get('translator')->trans($message, $args);
        } catch (ServiceNotFoundException $e) {
            return \strtr($message, $args);
        }
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
    private function parseRange($contentRange): array
    {
        $matches = [];
        if (!\preg_match('@^bytes (\d+)-(\d+)/(\d+)$@', \trim($contentRange), $matches)) {
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

        return [(int)$start, (int)$stop, (int)$filesize];
    }

    /**
     * File chunk remove
     */
    public function remove(Request $request): Response
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
    private function validateUploadedFile(FieldConfig $config, string $filename, int $filesize): ?string
    {
        // @todo should be modved out to another class, this is not the
        //    controller responsability to do this.
        if ($filesize && ($maxSize = $config->getMaxSize()) && $maxSize < $filesize) {
            return $this->translate(
                "Maximum file size allowed is @mega mo",
                ['@mega' => \round($maxSize / 1024 / 1024, 1)]
            );
        }

        return null;
    }

    /**
     * Validate uploaded file mimetype using session token.
     */
    private function validateMimeType(FieldConfig $config, string $filename): ?string
    {
        if (!\class_exists(MimeTypes::class)) {
            return null; // Symfony >= 4.4 required, other are not supported.
        }

        if ($mimeType = MimeTypes::getDefault()->guessMimeType($filename)) {
            if (!$config->isMimeTypeAllowed($mimeType)) {
                // Fake a file constraint for transparent translator support with
                // default Symfony translation.
                $constraint = new FileConstraint([
                    'mimeTypes' => $config->getAllowedMimeTypes(),
                ]);
                return $this->translate($constraint->mimeTypesMessage, [
                    '{{ type }}' => $mimeType,
                    '{{ types }}' => implode(', ', $constraint->mimeTypes),
                ]);
            }
        }

        return null;
    }

    /**
     * Endpoint to generate a token, for front apps.
     */
    public function token(FileSessionHandler $sessionHandler, Request $request): Response
    {
        if (!$request->isMethod('POST')) {
            throw $this->createAccessDeniedException();
        }

        return $this->json([
            'token' => $sessionHandler->getCurrentToken(),
        ]);
    }

    /**
     * Cleanup incimming filename that might have been encoded
     */
    private function cleanupIncommingFilename(string $filename): string
    {
        // Filename is required
        if (empty($filename)) {
            throw $this->createAccessDeniedException();
        }
        // File name might be encoded in base64 to avoid encoding errors
        if ('==' === \substr($filename, -2) || (false === \strpos($filename, '.') &&
            \preg_match('#^[a-zA-Z0-9\+/]+={0,2}$#ims', $filename))
        ) {
            $filename = \base64_decode($filename);
        }

        // JavaScript widget will use encodeURIComponent() in which space char is
        // encoded using %20 and not +, so we are safe to use rawurldecode() and not
        // urldecode() here.
        return \rawurldecode($filename);
    }

    /**
     * Build file from incomming input
     */
    private function buildFile(string $directory, int $filesize,
        string $filename, int $start, int $length): FileBuilder
    {
        try {
            $input = \fopen("php://input", "rb");
            if (!$input) {
                throw new \RuntimeException("Could not open HTTP POST input stream");
            }

            // @todo get user identifier if possible
            $builder = new FileBuilder($filesize, $filename, $directory);
            $builder->write($input, $start, $length);

            return $builder;

        } finally {
            // 'Unknown' mean the stream was already closed.
            // Symfony error handler, when in debug mode, will convert the
            // silenced erroneous \fclose() call into an exception and prevent
            // this from working gracefully.
            if (\is_resource($input) && 'Unknown' !== \get_resource_type($input)) {
                @\fclose($input);
            }
        }
    }

    /**
     * Upload endpoint.
     */
    public function upload(FileSessionHandler $sessionHandler,
        FileManager $fileManager, Request $request,
        EventDispatcherInterface $eventDispatcher): Response
    {
        if (!$request->isMethod('POST')) {
            throw $this->createAccessDeniedException();
        }

        $directory = $strategy = null;
        $filename = $this->cleanupIncommingFilename($request->headers->get('X-File-Name'));

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

        // Token validation
        if (!$sessionHandler->isTokenValid($token)) {
            throw $this->createAccessDeniedException();
        }

        // X-File-Field was added recently, in order to avoid API breakage, it
        // remains optional, older JS still have versions will have nasty bugs.
        $config = null;
        if ($fieldname = $request->headers->get('X-File-Field', '')) {
            if (!$config = $sessionHandler->getFieldConfig($fieldname)) {
                throw $this->createAccessDeniedException();
            }
            // Validate incomming file against stored field configuration.
            // Configuration comes from session, there is no way the end user
            // could have messed it up.
            if ($message = $this->validateUploadedFile($config, $filename, $filesize)) {
                return $this->json(['message' => $message], 403);
            }
            // If we have a config, and config orders us to move the file
            // into another place, then do it.
            $directory = $config->getTargetDirectory();
            $strategy = $config->getNamingStrategy();
        }

        $builder = $this->buildFile(
            $sessionHandler->getTemporaryFilePath($fieldname),
            $filesize, $filename, $start, $length
        );

        $filepath = $builder->getAbsolutePath();
        if (0 === $start && $config) {
            if ($message = $this->validateMimeType($config, $filepath)) {
                $builder->delete();
                return $this->json(['message' => $message], 403);
            }
        }

        $fileUrl = null;
        $isComplete = $builder->isComplete();
        $sha1sum = null;

        if ($isComplete && $directory) {
            // Move the file to whatever place the configuration ordered us.
            $filepath = $fileManager->renameIfNotWithin($filepath, $directory, 0, $strategy);
            $file = $fileManager->createSymfonyFile($filepath, true);
        } else {
            $file = $builder->getFile();
        }

        if ($isComplete) {
            $sha1sum = \sha1_file($filepath);
            $event = FileEvent::with($filepath, $filesize, $sha1sum, $file->getMimeType(), $config);
            $eventDispatcher->dispatch($event, FileEvent::EVENT_UPLOAD_FINISHED);
            if ($event->hasFileMoved()) {
                $filepath = $event->getFileUri();
            }
        }

        if ($fileUrl = $fileManager->getFileUrl($filepath)) {
            $fileUrl = '/'.$fileUrl;
        }

        return $this->json([
            'fid' => $file->getFilename(),
            'filename' => \basename($filepath),
            'finished' => $isComplete,
            'hash' => $sha1sum,
            'mimetype' => $file->getMimeType(),
            'offset' => $builder->getOffset(),
            'preview' => $file->getFilename(),
            'url' => $fileUrl,
            'writen' => $builder->getLastWriteSize(),
        ]);
    }

    /**
     * Delete endpoing
     */
    public function deleteAction(Request $request): Response
    {
        throw new \Exception("Not implemented yet");
    }
}
