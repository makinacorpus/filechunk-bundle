<?php

namespace MakinaCorpus\FilechunkBundle\StreamWrapper;

use MakinaCorpus\FilechunkBundle\FileManager;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Originally from Drupal 8.8.x, deeply modified.
 *
 * @codeCoverageIgnore
 *   This should be tested. But how?
 */
class LocalStreamWrapper
{
    public $context;
    public $handle = null;

    /**
     * Get local path for file
     */
    private function getLocalPath(string $uri): string
    {
        return FileManager::getInstance()->getAbsolutePath($uri);
    }

    /**
     * {@inheritdoc}
     */
    public function stream_open($uri, $mode, $options, &$opened_path)
    {
        $path = $this->getLocalPath($uri);
        $this->handle = ($options & STREAM_REPORT_ERRORS) ? \fopen($path, $mode) : @\fopen($path, $mode);

        if ((bool)$this->handle && $options & STREAM_USE_PATH) {
            $opened_path = $path;
        }

        return (bool)$this->handle;
    }

    /**
     * {@inheritdoc}
     */
    public function stream_lock($operation)
    {
        if (\in_array($operation, [LOCK_SH, LOCK_EX, LOCK_UN, LOCK_NB])) {
            return \flock($this->handle, $operation);
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function stream_read($count)
    {
        return \fread($this->handle, $count);
    }

    /**
     * {@inheritdoc}
     */
    public function stream_write($data)
    {
        return \fwrite($this->handle, $data);
    }

    /**
     * {@inheritdoc}
     */
    public function stream_eof()
    {
        return \feof($this->handle);
    }

    /**
     * {@inheritdoc}
     */
    public function stream_seek($offset, $whence = SEEK_SET)
    {
        // fseek returns 0 on success and -1 on a failure.
        // stream_seek 1 on success and  0 on a failure.
        return !\fseek($this->handle, $offset, $whence);
    }

    /**
     * {@inheritdoc}
     */
    public function stream_flush()
    {
        return \fflush($this->handle);
    }

    /**
     * {@inheritdoc}
     */
    public function stream_tell()
    {
        return \ftell($this->handle);
    }

    /**
     * {@inheritdoc}
     */
    public function stream_stat()
    {
        return \fstat($this->handle);
    }

    /**
     * {@inheritdoc}
     */
    public function stream_close()
    {
        return \fclose($this->handle);
    }

    /**
     * {@inheritdoc}
     */
    public function stream_cast($cast_as)
    {
        return $this->handle ? $this->handle : false;
    }

    /**
     * {@inheritdoc}
     */
    public function stream_metadata($uri, $option, $value)
    {
        $target = $this->getLocalPath($uri);

        switch ($option) {

            case STREAM_META_TOUCH:
                if (!empty($value)) {
                    return \touch($target, $value[0], $value[1]);
                } else {
                    return \touch($target);
                }

            case STREAM_META_OWNER_NAME:
            case STREAM_META_OWNER:
                return \chown($target, $value);

            case STREAM_META_GROUP_NAME:
            case STREAM_META_GROUP:
                return \chgrp($target, $value);

            case STREAM_META_ACCESS:
                return \chmod($target, $value);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function stream_set_option($option, $arg1, $arg2)
    {
        \trigger_error('stream_set_option() not supported for local file based stream wrappers', E_USER_WARNING);

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function stream_truncate($new_size)
    {
        return \ftruncate($this->handle, $new_size);
    }

    /**
     * {@inheritdoc}
     */
    public function unlink($uri)
    {
        // FIXME
        return drupal_unlink($this->getLocalPath($uri));
    }

    /**
     * {@inheritdoc}
     */
    public function rename($from_uri, $to_uri)
    {
        return \rename($this->getLocalPath($from_uri), $this->getLocalPath($to_uri));
    }

    /**
     * {@inheritdoc}
     */
    public function mkdir($uri, $mode, $options)
    {
        $recursive = (bool)($options & STREAM_MKDIR_RECURSIVE);
        $localpath = $this->getLocalPath($uri);
        $filesystem = new Filesystem();

        if ($options & STREAM_REPORT_ERRORS) {
            return $filesystem->mkdir($localpath, $mode, $recursive);
        } else {
            return @$filesystem->mkdir($localpath, $mode, $recursive);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function rmdir($uri, $options)
    {
        $filesystem = new Filesystem();

        if ($options & STREAM_REPORT_ERRORS) {
            return $filesystem->rmdir($this->getLocalPath($uri));
        } else {
            return @$filesystem->rmdir($this->getLocalPath($uri));
        }
    }

    /**
     * {@inheritdoc}
     */
    public function url_stat($uri, $flags)
    {
        $path = $this->getLocalPath($uri);

        // Suppress warnings if requested or if the file or directory does not
        // exist. This is consistent with PHP's plain filesystem stream wrapper.
        if ($flags & STREAM_URL_STAT_QUIET || !\file_exists($path)) {
          return @\stat($path);
        } else {
          return \stat($path);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function dir_opendir($uri, $options)
    {
        $this->handle = \opendir($this->getLocalPath($uri));

        return (bool)$this->handle;
    }

    /**
     * {@inheritdoc}
     */
    public function dir_readdir()
    {
        return \readdir($this->handle);
    }

    /**
     * {@inheritdoc}
     */
    public function dir_rewinddir()
    {
        \rewinddir($this->handle);

        return true; // rewinddir() returns void
    }

    /**
     * {@inheritdoc}
     */
    public function dir_closedir()
    {
      \closedir($this->handle);

      return true; // \closedir() returns void
    }
}
