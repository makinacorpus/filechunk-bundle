<?php

namespace MakinaCorpus\FilechunkBundle\File;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOException;

class FileBuilder
{
    private $file;
    private $filename;
    private $filesize;
    private $offset = 0;
    private $path;

    /**
     * Default constructor
     *
     * @param int $filesize
     *   Target file size
     * @param string $filename
     *   Target file name
     * @param string $path
     *   The optional path to work in, if nothing given the system temporary
     *   directory will be used instead
     */
    public function __construct($filesize, $filename, $path = null)
    {
        if (!is_int($filesize) || !0 < $filesize) {
            throw new \InvalidArgumentException("There is no point in creating an empty file");
        }

        if (!$path) {
            $path = sys_get_temp_dir();
        }

        $this->path = $path;
        $this->filesize = (int)$filesize;
        $this->filename = $filename;

        $fileSystem = new Filesystem();
        $fileSystem->mkdir(dirname($this->getAbsolutePath()));

        $this->readMetadataFile();
    }

    /**
     * Get absolute file path
     *
     * @return string
     */
    public function getAbsolutePath()
    {
        return $this->path.'/'.$this->filename;
    }

    private function deleteMetadataFile()
    {
        $metadataFile = $this->getAbsolutePath() . '.metadata.json';
        if (file_exists($metadataFile) && !@unlink($metadataFile)) {
            throw new IOException(sprintf("%s: could not delete file", $metadataFile));
        }
    }

    private function writeMetadataFile()
    {
        $metadataFile = $this->getAbsolutePath() . '.metadata.json';

        $contents = json_encode(['size' => $this->filesize, 'offset' => $this->offset]);

        $success = file_put_contents($metadataFile, $contents);
        if (!$success) {
            throw new IOException(sprintf("%s: could not write to file", $metadataFile));
        }
    }

    /**
     * Create file if not exists; and ensure data consistency
     */
    private function readMetadataFile()
    {
        $absolutePath = $this->getAbsolutePath();
        $metadataFile = $absolutePath . '.metadata.json';

        if (!file_exists($absolutePath)) {
            if (file_exists($metadataFile)) {
                // Delete the metadata file since it's probably something
                // that stalled from a previous attempt.
                if (!@unlink($metadataFile)) {
                    throw new IOException(sprintf("%s: could not delete stalled file", $metadataFile));
                }
            }
        } else if (file_exists($metadataFile)) {
            // Read file metadata and do some security checks
            try {
                $metadata = file_get_contents($metadataFile);
                if (false === $metadata) {
                    throw new IOException(sprintf("%s: cannot read metadata file", $metadataFile));
                }

                $metadata = json_decode($metadata);
                if (!$metadata) {
                    throw new \RuntimeException(sprintf("%s: invalid metadata file", $metadataFile));
                }

                // If filesize does not match, this probably means that another
                // file is being sent or the JavaScript is corrupted.
                if ($this->filesize !== (int)$metadata->size) {
                    throw new \RuntimeException(sprintf("%s: filesize mismatch, %d given, %d awaited", $absolutePath, $this->filesize, (int)$metadata->size));
                }
                $this->offset = (int)$metadata->offset;

            } catch (\Exception $e) {

                // Attempt to delete the invalid metadata file
                if (!@unlink($metadataFile)) {
                    throw new IOException(sprintf("%s: could not delete file", $metadataFile), null, $e);
                }

                throw $e;
            }
        } else {
            // @todo should we ensure the file size?
        }

        // @todo we should lock somehow the fact that we and only we
        //   are handling this file
    }

    /**
     * Ensure file is created and get an handle on the file
     *
     * @return resource
     *   Opened file handle
     */
    private function openFile()
    {
        $fileHandle = null;
        $absolutePath = $this->getAbsolutePath();

        try {
            if (!is_file($absolutePath)) {
                // From this point, we did read on create new metadata without any
                // problems, we can safely assume the file is legit and we may reserve
                // file space on the file system.

                // This solution comes from https://stackoverflow.com/a/3608405/5826569
                // Best answer ever to this!
                // 'cb' allows overwrite.
                $fileHandle = fopen($absolutePath, 'cb');
                if (!$fileHandle) {
                    throw new IOException(sprintf("%s: cannot create file", $absolutePath));
                }
                fseek($fileHandle, $this->filesize - 1);
                $success = fwrite($fileHandle, 'a');

                if (false === $success) {
                    throw new IOException(sprintf("%s: cannot create file", $absolutePath));
                }

            } else {
                // Also, an already existing file with a different filesize would
                // mean there is a security issue, it's attempting to overwrite
                // someone else's file
                $existingFilesize = filesize($absolutePath);
                if ($this->filesize !== $existingFilesize) {
                    throw new \RuntimeException(sprintf("%s: existing filesize mismatch, %s given, %s existing", $absolutePath, $this->filesize, $existingFilesize));
                }

                $fileHandle = fopen($absolutePath, 'cb');
                if (!$fileHandle) {
                    throw new IOException(sprintf("%s: cannot open file", $absolutePath));
                }
            }
        } catch (\Exception $e) {
            // Always close the file resource in case of error
            if ($fileHandle) {
                @fclose($fileHandle);
            }

            throw $e;
        }

        return $fileHandle;
    }

    /**
     * Write stream to file at the given position
     *
     * @param resource $input
     *   Incomming data, must be an already open file resource
     * @param int $start
     *   Where to start
     * @param int $length
     *   Length to write
     *
     * @return boolean
     *   True in case of success, false if start offset does not match
     *   current existing file offset
     */
    public function write($input, $start, $length = null)
    {
        if ($this->offset !== $start) {
            return false;
        }

        $output = null;
        $writen = 0;

        try {
            $output = $this->openFile();

            // Write chunk into file.
            if (-1 === fseek($output, $start)) {
                throw new IOException("Could not seek output stream");
            }

            if ($length) {
                $writen = stream_copy_to_stream($input, $output, $length);
            } else {
                $writen = stream_copy_to_stream($input, $output);
            }

            @fclose($output);
            @fclose($input);

            $this->offset = $start + $length;

            if ($this->isComplete()) {
                $this->deleteMetadataFile();
            } else {
                $this->writeMetadataFile();
            }
        } finally {
            if ($output) {
                @fclose($output);
            }
        }

        return $writen;
    }

    /**
     * Get current offset to restart at
     *
     * @return int
     */
    public function getOffset()
    {
        return $this->offset;
    }

    /**
     * Has this file been completely written
     */
    public function isComplete()
    {
        return $this->offset === $this->filesize;
    }

    /**
     * Get file representation
     *
     * @return UploadedFile
     */
    public function getFile()
    {
        return new UploadedFile($this->getAbsolutePath(), $this->filename);
    }
}
