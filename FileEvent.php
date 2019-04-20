<?php

declare(strict_types=1);

namespace MakinaCorpus\FilechunkBundle;

use Symfony\Component\EventDispatcher\Event;

/**
 * File events
 */
final class FileEvent extends Event
{
    const EVENT_UPLOAD_FINISHED = 'filechunk:upload';

    private $fieldConfig;
    private $filesize;
    private $hasMoved = false;
    private $mimetype;
    private $originalUri;
    private $sha1sum;
    private $uri;

    /**
     * Build event instance with already unpacked file information
     */
    public static function with(string $uri, int $filesize,
        ?string $sha1sum = null, ?string $mimetype = null,
        ?FieldConfig $fieldConfig = null): self
    {
        return new self($uri, $filesize, $sha1sum, $mimetype, $fieldConfig);
    }

    /**
     * Default constructor
     */
    private function __construct(string $uri, int $filesize,
        ?string $sha1sum = null, ?string $mimetype = null,
        ?FieldConfig $fieldConfig = null)
    {
        $this->fieldConfig = $fieldConfig;
        $this->filesize = $filesize;
        $this->mimetype = $mimetype;
        $this->sha1sum = $sha1sum;
        $this->uri = $this->originalUri = $uri;
    }

    /**
     * If your event has moved the file, set the new URI using this method
     */
    public function setNewLocation(string $uri): self
    {
        $this->uri = $uri;
        if ($uri !== $this->originalUri) {
            $this->hasMoved = true;
        }

        return $this;
    }

    /**
     * Get original file URI
     */
    public function getOriginalFileUri(): string
    {
        return $this->originalUri;
    }

    /**
     * Was the file moved by a listener?
     */
    public function hasFileMoved(): bool
    {
        return $this->hasMoved;
    }

    /**
     * Does this event carries an associated field config?
     */
    public function hasFieldConfig(): bool
    {
        return null !== $this->fieldConfig;
    }

    /**
     * Get field config if any
     */
    public function getFieldConfig(): ?FieldConfig
    {
        return $this->fieldConfig;
    }

    /**
     * Get file URI, it might have been moved by other listeners
     */
    public function getFileUri(): string
    {
        return $this->uri;
    }

    /**
     * Get file size
     */
    public function getFilesize(): int
    {
        return $this->filesize;
    }

    /**
     * Get file SHA1 summary
     */
    public function getSha1Summary(): string
    {
        return $this->sha1sum;
    }
}
