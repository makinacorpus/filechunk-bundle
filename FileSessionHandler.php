<?php

declare(strict_types=1);

namespace MakinaCorpus\FilechunkBundle;

use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

final class FileSessionHandler
{
    const SESSION_TOKEN = 'filechunk_token';

    private $debug;
    private $fileManager;
    private $session;
    private $uploadDirectory;

    /**
     * Default constructor
     */
    public function __construct(FileManager $fileManager, SessionInterface $session)
    {
        $this->fileManager = $fileManager;
        $this->session = $session;
    }

    /**
     * Get and generated if missing the global security token
     */
    public function getCurrentToken() : string
    {
        if (!$token = $this->session->get(self::SESSION_TOKEN)) {
            $token = \base64_encode(\mt_rand().\mt_rand().\mt_rand());
            $this->session->set(self::SESSION_TOKEN, $token);
        }

        return $token;
    }

    /**
     * Find upload directory and create it if it does not exist
     */
    private function ensureUploadDirectory(): string
    {
        if ($this->fileManager->isKnownScheme(FileManager::SCHEME_UPLOAD)) {
            $directory = $this->fileManager->getWorkingDirectory(FileManager::SCHEME_UPLOAD);
        } else if ($this->fileManager->isKnownScheme(FileManager::SCHEME_TEMPORARY)) {
            $directory = $this->fileManager->getWorkingDirectory(FileManager::SCHEME_TEMPORARY).'/filechunk';
        } else {
            $directory = \sys_get_temp_dir().'/filechunk';
        }

        if (\file_exists($directory)) {
            if (!\is_dir($directory)) {
                throw new IOException(\sprintf("%s: not a directory", $directory));
            }
            if (!\is_writable($directory)) {
                throw new IOException(\sprintf("%s: is not writable", $directory));
            }
        } else {
            (new Filesystem())->mkdir($directory);
        }

        return $directory;
    }

    /**
     * Get upload directory
     */
    public function getUploadDirectory() : string
    {
        return $this->uploadDirectory ?? ($this->uploadDirectory = $this->ensureUploadDirectory());
    }

    /**
     * From the given field name, get the temporary file name
     */
    public function getTemporaryFilePath(?string $name = null) : string
    {
        return $this->getUploadDirectory().'/'.$this->getCurrentToken().($name ? '/'.$name : '');
    }

    /**
     * Ensure that given input is valid
     */
    public function isTokenValid(string $token) : bool
    {
        return $this->session->has(self::SESSION_TOKEN) && $this->session->get(self::SESSION_TOKEN) === $token;
    }

    /**
     * Create a session key for given field name
     */
    private function getFieldSessionKey(string $name) : string
    {
        return 'filechunk_'.$this->getCurrentToken().'_'.$name;
    }

    /**
     * Add a single field configuration
     */
    public function addFieldConfig(string $name, array $config)
    {
        $this->session->set($this->getFieldSessionKey($name), $config);
    }

    /**
     * Get a single field configuration
     */
    public function getFieldConfig(string $name) : array
    {
        return $this->session->get($this->getFieldSessionKey($name), []);
    }
}
