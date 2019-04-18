<?php

namespace MakinaCorpus\FilechunkBundle;

use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

class FileSessionHandler
{
    const SESSION_TOKEN = 'filechunk_token';

    private $debug;
    private $session;
    private $uploadDirectory;

    /**
     * Default constructor
     */
    public function __construct(SessionInterface $session, string $uploadDirectory = '')
    {
        $this->session = $session;
        $this->uploadDirectory = $uploadDirectory ? $uploadDirectory : (sys_get_temp_dir().'/filechunk');

        if (file_exists($this->uploadDirectory)) {
            if (!is_dir($this->uploadDirectory)) {
                throw new IOException(sprintf("%s: not a directory", $this->uploadDirectory));
            }
            if (!is_writable($this->uploadDirectory)) {
                throw new IOException(sprintf("%s: is not writable", $this->uploadDirectory));
            }
        } else {
            (new Filesystem())->mkdir($this->uploadDirectory);
        }
    }

    /**
     * Get and generated if missing the global security token
     */
    public function getCurrentToken() : string
    {
        if (!$token = $this->session->get(self::SESSION_TOKEN)) {
            $token = base64_encode(mt_rand() . mt_rand() . mt_rand());
            $this->session->set(self::SESSION_TOKEN, $token);
        }

        return $token;
    }

    /**
     * @internal
     *   For unit testing
     */
    public function regenerateToken() : string
    {
        $this->session->remove(self::SESSION_TOKEN);

        return $this->getCurrentToken();
    }

    /**
     * Get upload directory
     */
    public function getUploadDirectory() : string
    {
        return $this->uploadDirectory;
    }

    /**
     * From the given field name, get the temporary file name
     */
    public function getTemporaryFilePath(string $name = '') : string
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
