<?php

declare(strict_types=1);

namespace MakinaCorpus\FilechunkBundle\FileSessionHandler;

use MakinaCorpus\FilechunkBundle\FieldConfig;
use MakinaCorpus\FilechunkBundle\FileSessionHandler;
use MakinaCorpus\Files\FileManager;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

abstract class AbstractFileSessionHandler implements FileSessionHandler
{
    private bool $debug = false;
    private array $globalFields = [];
    private ?string $uploadDirectory = null;

    public function __construct(private FileManager $fileManager)
    {
        $this->fileManager = $fileManager;
    }

    /**
     * Get current swession.
     */
    protected abstract function getSession(): SessionInterface;

    /**
     * {@inheritdoc}
     */
    public function getCurrentToken(): string
    {
        $session = $this->getSession();

        if (!$token = $session->get(self::SESSION_TOKEN)) {
            $token = \base64_encode(\mt_rand().\mt_rand().\mt_rand());
            $session->set(self::SESSION_TOKEN, $token);
        }

        return $token;
    }

    /**
     * {@inheritdoc}
     */
    public function regenerateToken(): string
    {
        $this->getSession()->remove(self::SESSION_TOKEN);

        return $this->getCurrentToken();
    }

    /**
     * {@inheritdoc}
     */
    public function getUploadDirectory(): string
    {
        return $this->uploadDirectory ?? ($this->uploadDirectory = $this->ensureUploadDirectory());
    }

    /**
     * {@inheritdoc}
     */
    public function getTemporaryFilePath(?string $name = null): string
    {
        return $this->getUploadDirectory().'/'.$this->getCurrentToken().($name ? '/'.$name : '');
    }

    /**
     * {@inheritdoc}
     */
    public function isTokenValid(string $token): bool
    {
        $session = $this->getSession();

        return $session->has(self::SESSION_TOKEN) && $session->get(self::SESSION_TOKEN) === $token;
    }

    /**
     * {@inheritdoc}
     */
    public function addGlobalFieldConfig(FieldConfig $config): void
    {
        $this->globalFields[$config->getName()] = $config;
    }

    /**
     * {@inheritdoc}
     */
    public function getGlobalFieldConfig(string $name): ?FieldConfig
    {
        return $this->globalFields[$name] ?? null;
    }

    /**
     * {@inheritdoc}
     */
    public function addFieldConfig(FieldConfig $config): void
    {
        $this->getSession()->set($this->getFieldSessionKey($config->getName()), $config);
    }

    /**
     * {@inheritdoc}
     */
    public function getFieldConfig(string $name): ?FieldConfig
    {
        return $this->getSession()->get($this->getFieldSessionKey($name)) ?? $this->getGlobalFieldConfig($name);
    }

    /**
     * Create a session key for given field name
     */
    protected function getFieldSessionKey(string $name): string
    {
        return 'filechunk_'.$this->getCurrentToken().'_'.$name;
    }

    /**
     * Find upload directory and create it if it does not exist.
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
}
