<?php

declare(strict_types=1);

namespace MakinaCorpus\FilechunkBundle;

interface FileSessionHandler
{
    const SESSION_TOKEN = 'filechunk_token';

    /**
     * Get and generated if missing the global security token.
     */
    public function getCurrentToken(): string;

    /**
     * @internal
     *   For unit testing.
     */
    public function regenerateToken(): string;

    /**
     * Get upload directory.
     */
    public function getUploadDirectory(): string;

    /**
     * From the given field name, get the temporary file name.
     */
    public function getTemporaryFilePath(?string $name = null): string;

    /**
     * Ensure that given input is valid.
     */
    public function isTokenValid(string $token): bool;

    /**
     * Add global field config
     */
    public function addGlobalFieldConfig(FieldConfig $config): void;

    /**
     * Get global field config
     */
    public function getGlobalFieldConfig(string $name): ?FieldConfig;

    /**
     * Add a single field configuration
     */
    public function addFieldConfig(FieldConfig $config): void;

    /**
     * Get a single field configuration
     */
    public function getFieldConfig(string $name) : ?FieldConfig;
}
