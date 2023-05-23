<?php

declare(strict_types=1);

namespace MakinaCorpus\FilechunkBundle;

use MakinaCorpus\Files\FileManager;

final class FieldConfig
{
    private $maxCount = 1;
    private $maxSize;
    private $mimeTypes = [];
    private $name;
    private $namingStrategy;
    private $targetDirectory;

    /**
     * Use self::fromArray()
     */
    private function __construct()
    {
    }

    private static function parsePositiveInt($value, bool $allowInvalid = false): ?int
    {
        if (empty($value)) {
            return null;
        }
        if (!\is_numeric($value) || $value < 1) {
            if (!$allowInvalid) {
                throw new \InvalidArgumentException("Argument must be an int");
            }
            return null;
        }
        return (int)$value; // @todo
    }

    /**
     * Parse size expressed as a string
     */
    private static function parseSize($value, bool $allowInvalid = false): ?int
    {
        if (empty($value)) {
            return null;
        }
        if (!\is_string($value) && !\is_int($value)) {
            if (!$allowInvalid) {
                throw new \InvalidArgumentException("Argument must be an int or a string");
            }
            return null;
        }
        return (int)$value; // @todo
    }

    /**
     * Parse file system path
     */
    private static function parsePath($value, bool $allowInvalid = false): ?string
    {
        if (empty($value)) {
            return null;
        }
        if (!\is_string($value)) {
            if (!$allowInvalid) {
                throw new \InvalidArgumentException("Argument must be a string");
            }
            return null;
        }
        return FileManager::normalizePath($value);
    }

    /**
     * Create instance from configuration
     */
    public static function fromArray(string $name, array $input, bool $allowInvalid = false): self
    {
        $ret = new self();
        $ret->maxCount = self::parsePositiveInt($input['maxcount'] ?? null, $allowInvalid);
        $ret->maxSize = self::parseSize($input['maxsize'] ?? null, $allowInvalid);
        $ret->mimeTypes = $input['mimetypes'] ?? [];
        $ret->name = $name;
        $ret->namingStrategy = $input['naming_strategy'] ?? null;
        $ret->targetDirectory = self::parsePath($input['target_directory'] ?? null);

        return $ret;
    }

    /**
     * Get maximum file count
     */
    public function getMaxCount(): ?int
    {
        return $this->maxCount;
    }

    /**
     * Get file maximum size
     */
    public function getMaxSize(): ?int
    {
        return $this->maxSize;
    }

    /**
     * Is mimetype allowed
     */
    public function isMimeTypeAllowed(string $mimeType): bool
    {
        if (!$this->mimeTypes) {
            return true;
        }
        return \in_array($mimeType, $this->mimeTypes);
    }

    /**
     * Return allowed mimetypes
     */
    public function getAllowedMimeTypes(): ?array
    {
        return $this->mimeTypes;
    }

    /**
     * Get field name
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get file naming strategy
     */
    public function getNamingStrategy(): ?string
    {
        return $this->namingStrategy;
    }

    /**
     * Get target directory
     */
    public function getTargetDirectory(): ?string
    {
        return $this->targetDirectory;
    }
}
