<?php

declare(strict_types=1);

namespace MakinaCorpus\FilechunkBundle;

use MakinaCorpus\FilechunkBundle\StreamWrapper\LocalStreamWrapper;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Exception\FileNotFoundException;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\MimeType\MimeTypeGuesser;

final class FileManager
{
    const MODE_DIR = 0777;
    const MODE_FILE = 0666;
    const SCHEME_LOCAL = 'file';
    const SCHEME_PRIVATE = 'private';
    const SCHEME_PUBLIC = 'public';
    const SCHEME_TEMPORARY = 'temporary';
    const SCHEME_UPLOAD = 'upload';
    const SCHEME_WEBROOT = 'webroot';

    /**
     * On name conflict when moving a file, raise an exception, this is the
     * default behaviour when no flags are given.
     */
    const MOVE_CONFLICT_ERROR = 8;

    /**
     * On name conflict when moving a file, overwrite the existing one.
     */
    const MOVE_CONFLICT_OVERWRITE = 4;

    /**
     * On name conflict when moving a file, rename the current file
     * using one of the strategy patterns exposed below.
     */
    const MOVE_CONFLICT_RENAME = 2;

    /**
     * Renaming strategy: increment a counter at the end of file name, this
     * is the default behaviour when no flags are given.
     */
    const STRATEGY_RENAME_INC = 16;

    /**
     * No strategy, just put the file in the destination folder, this is the
     * default behaviour.
     */
    const STRATEGY_DIRNAME_NONE = null;

    /**
     * Put file in a sub-directory in the form /YYYY/MM/DD/FILE.
     */
    const STRATEGY_DIRNAME_DATE = 'date';

    /**
     * Put file in a sub-directory in the form /YYYY/MM/DD/HH/II/FILE.
     */
    const STRATEGY_DIRNAME_DATETIME = 'datetime';

    /**
     * We need this for stream wrappers.
     */
    private static $instance;

    /**
     * @var array
     *   Keys are schemes (such as "file" or "public") values are the working
     *   directory. Directory could itself be using a scheme (such as "sftp://")
     *   for example, as long as PHP can handle those natively with its stream
     *   wrapper API.
     */
    private $knownSchemes = [];

    private $webroot;

    /**
     * Default constructor
     */
    public function __construct(array $knownSchemes = [], ?string $webroot = null)
    {
        foreach ($knownSchemes as $scheme => $workindDirectory) {
            if (!\preg_match('@^[a-z0-9]+$@i', $scheme)) {
                throw new \InvalidArgumentException(\sprintf(
                    "Invalid scheme provided: '%s', it must only contains letters or numbers and cannot be empty",
                    $scheme
                ));
            }
            $this->knownSchemes[$scheme] = self::normalizePath($workindDirectory);
        }
        // Sort by descending length (longer first) - this makes natural the
        // nested scheme targets de-ambiguation when looking up for a matching
        // scheme from an absolute filename.
        \uasort($this->knownSchemes, function ($a, $b) {
            return \strlen($b) - \strlen($a);
        });

        $this->webroot = $webroot;

        self::initializeEnvironment($this);
    }

    /**
     * I am so sorry, but working with stream wrappers forces us to taint
     * the global scope.
     */
    private static function initializeEnvironment(self $instance)
    {
        self::$instance = $instance;

        $registered = stream_get_wrappers();
        foreach ($instance->getKnownSchemes() as $scheme => $workingDirectory) {
            if (\in_array($scheme, $registered)) {
                \stream_wrapper_unregister($scheme);
            }
            \stream_wrapper_register($scheme, LocalStreamWrapper::class,  0);
        }
    }

    /**
     * Get instance
     */
    public static function getInstance(): self
    {
        if (!self::$instance) {
            throw new \LogicException(\sprintf("'%s' instance must be programtically instanciated before using the static singleton", __CLASS__));
        }
        return self::$instance;
    }

    /**
     * Fail if scheme is unknown
     */
    private static function unknownScheme(string $scheme): string
    {
        throw new \InvalidArgumentException(\sprintf("Scheme '%s' is unknown", $scheme));
    }

    /**
     * Get known schemes
     */
    public function getKnownSchemes(): array
    {
        return $this->knownSchemes;
    }

    /**
     * Get scheme from URI
     */
    public static function getScheme(string $uri): ?string
    {
        // Skip false (not found) or 0 (start offset, no scheme)
        if ($pos = \strpos($uri, '://')) {
            return \substr($uri, 0, $pos);
        }
        return null;
    }

    /**
     * Strip scheme from URI
     */
    public static function stripScheme(string $uri): string
    {
        // Skip false (not found) or 0 (start offset, no scheme)
        if ($pos = \strpos($uri, '://')) {
            return \substr($uri, $pos + 3);
        }
        return $uri;
    }

    /**
     * Normalize a path by removing redundant '..', '.' and '/' and thus preventing the
     * need of using the realpath() function that may come with some side effects such
     * as breaking out open_basedir configuration by attempting to following symlinks
     */
    public static function normalizePath(string $string): string
    {
        // Handle windows gracefully
        if (DIRECTORY_SEPARATOR !== '/') {
            $string = \str_replace(DIRECTORY_SEPARATOR, '/', $string);
        }
        // Also tests some special cases we can't really do anything with
        if (false === \strpos($string, '/') || '/' === $string || '.' === $string || '..' === $string) {
            return $string;
        }
        // This is supposedly invalid, but an empty string is an empty string
        if ('' === ($string = \rtrim($string, '/'))) {
            return '';
        }
        $scheme = null;
        if (\strpos($string, '://')) {
            list($scheme, $string) = \explode('://', $string, 2);
        }
        // Matches useless '.' repetitions
        $string = \preg_replace('@^\./|(/\.)+/|/\.$@', '/', $string);
        $count = 0;
        do {
            // string such as '//' can be generated by the first regex, hence the second 
            $string = \preg_replace('@[^/]+/+\.\.(/+|$)@', '$2', \preg_replace('@//+@', '/', $string), -1, $count);
        } while ($count);
        // rtrim() a second time because preg_replace() could leave a trailing '/'
        return ($scheme ? ($scheme.'://') : '').\rtrim($string, '/');
    }

    /**
     * Strip local scheme (aka "file://" will be dropped from URI if found).
     */
    public static function stripLocalScheme(string $uri): string
    {
        if ('f' === $uri[0] /* speed! */ && 'file://' === \substr($uri, 0, 7)) {
            return \substr($uri, 7);
        }
        return $uri;
    }

    /**
     * Implementation of identify() working with an already normalized URI.
     */
    private function unsafeIdentify(string $uri): ?SchemeURI
    {
        $scheme = $this->getScheme($uri);

        // Deal with specific "file" scheme which means file within the local
        // filesystem, always strip it.
        if (self::SCHEME_LOCAL === $scheme) {
            $uri = \sprintf('/%s', \trim(\substr($uri, \strlen(self::SCHEME_LOCAL) + 3), '/'));
            $scheme = null;
        }

        if ($scheme) {
            if (isset($this->knownSchemes[$scheme])) {
                // Trim leading '/' to avoid double '/' in generated absolute path.
                return new SchemeURI($scheme, \ltrim(self::stripScheme($uri), '/'), $this->knownSchemes[$scheme]);
            } else {
                return null;
            }
        }

        // Dynamic lookup
        // De-ambiguation when a scheme working directory is nested within another
        // scheme working directory is natural because we sorted the known schemes
        // by working directory descending order.
        foreach ($this->knownSchemes as $scheme => $workingDirectory) {
            $length = \strlen($workingDirectory);
            if ($workingDirectory === \substr($uri, 0, $length)) {
                // Trim leading '/' to avoid double '/' in generated absolute path.
                return new SchemeURI($scheme, \ltrim(\substr($uri, $length), '/'), $workingDirectory);
            }
        }

        return null;
    }

    /**
     * Get working directory for scheme
     */
    public function getWorkingDirectory(string $scheme): string
    {
        return $this->knownSchemes[$scheme] ?? self::unknownScheme($scheme);
    }

    /**
     * Is given scheme known
     */
    public function isKnownScheme(string $scheme): bool
    {
        return isset($this->knownSchemes[$scheme]);
    }

    /**
     * Get working directory of given scheme or URI.
     *
     * If an URI outside of a known scheme, an absolute URI, a relative URI
     * without scheme, or an unknown scheme is given, this will return null.
     */
    public function identify(string $uri): ?SchemeURI
    {
        return $this->unsafeIdentify(self::normalizePath($uri));
    }

    /**
     * Internal implementation of isPathWithin().
     */
    private function unsafeIsPathWithin(string $filename, string $directory): bool
    {
        return 0 === \strpos($filename, $directory);
    }

    /**
     * Is given filename or URI within the given path
     */
    public function isPathWithin(string $uri, string $directory): bool
    {
        return $this->unsafeIsPathWithin(
            $this->getAbsolutePath($uri),
            $this->getAbsolutePath($directory)
        );
    }

    /**
     * Internal implementation of isDuplicateOf().
     *
     * In theory, it should be almost impossible (at the very least
     * it's very improbable) to have false positives.
     *
     * The only way to be conservative would to check bit per bit if files
     * are identical, maybe we'll switch to that one day.
     */
    private static function unsafeIsDuplicateOf(string $filename, string $otherFilename): bool
    {
        // First check size
        if (\filesize($filename) !== \filesize($otherFilename)) {
            return false;
        }

        try {
            $guesser = MimeTypeGuesser::getInstance();
            if ($guesser->guess($filename) !== $guesser->guess($otherFilename)) {
                return false;
            }
        } catch (\Throwable $e) {
            // Symfony might fail on mime type guesser initialization, let's
            // just be careful and return false in case.
            return false;
        }

        // And the worst part, sha1sum of the files.
        return \sha1_file($filename) === \sha1_file($otherFilename);
    }

    /**
     * Tell if the given files are a duplicate of each other
     */
    public function isDuplicateOf(string $someUri, string $otherUri): bool
    {
        return self::unsafeIsDuplicateOf(
            $this->getAbsolutePath($someUri),
            $this->getAbsolutePath($otherUri)
        );
    }

    /**
     * Internal implementation of deduplicate().
     */
    private function unsafeDeduplicate(string $filename): string
    {
        $ext = null;
        $basename = \basename($filename);
        $dirname = \dirname($filename);

        if ($pos = \strrpos($basename, '.')) {
            $ext = \substr($basename, $pos + 1);
            $basename = \substr($basename, 0, $pos);
        }

        $counter = 0;
        do {
            $counter++; // Starts at 1. It's fine.
            if ($ext) {
                $candidate = \sprintf("%s/%s_%d.%s", $dirname, $basename, $counter, $ext);
            } else {
                $candidate = \sprintf("%s/%s_%d", $dirname, $basename, $counter);
            }
        } while (\file_exists($candidate));

        return $candidate;
    }

    /**
     * Use finder to extract files list from a directory
     * 
     * @param string $uri is the directory uri
     * @param string $pattern is an optionnal patteren restriction (like '*.csv')
     * @param bool $createDirectoryIfNotExists is False by default, if true the $uri
     *        directory would be created if not exists.
     */
    public function ls(string $uri, $pattern = '*', $createDirectoryIfNotExists = false): Finder
    {

        if (! $this->exists($uri)) {
            if ($createDirectoryIfNotExists) {
                $this->mkdir($uri);
            } else {
                throw new FileNotFoundException();
            }
        }
        $finder = new Finder();

        return $finder
            ->ignoreUnreadableDirs()
            ->followLinks()
            ->files()
            ->name($pattern)
            ->in($this->getAbsolutePath($uri));
    }

    /**
     * Does file or directory exists.
     */
    public function exists(string $uri): bool
    {
        return (new Filesystem())->exists($this->getAbsolutePath($uri));
    }

    /**
     * Deduplicate file name in its folder.
     */
    public function deduplicate(string $uri): string
    {
        return $this->getURI($this->unsafeDeduplicate($this->getAbsolutePath($uri)));
    }

    /**
     * Internal implementation for both rename() and renameIfNotWithin().
     */
    private function unsafeRename(string $filename, string $directory,
        ?int $flags = null, ?string $strategy = null, ?int $mode = null): string
    {
        if (!\file_exists($filename)) {
            throw new IOException(\sprintf("File '%s' does not exist", $filename));
        }

        if ($strategy) {
            switch ($strategy) {

                case self::STRATEGY_DIRNAME_DATE:
                    $date = new \DateTimeImmutable();
                    $destination = \sprintf(
                        "%s/%s/%s/%s",
                        $directory,
                        $date->format('Y'), $date->format('m'), $date->format('d')
                    );
                    break;

                case self::STRATEGY_DIRNAME_DATETIME:
                    $date = new \DateTimeImmutable();
                    $destination = \sprintf(
                        "%s/%s/%s/%s/%s/%s",
                        $directory,
                        $date->format('Y'), $date->format('m'), $date->format('d'),
                        $date->format('h'), $date->format('i')
                    );
                    break;

                default:
                    throw new \InvalidArgumentException(\sprintf("Unknown directory strategy: '%s'", $strategy));
            }
        } else {
            $destination = $directory;
        }

        $filesystem = new Filesystem();
        $filesystem->mkdir($destination);

        if (!$flags) {
            $flags = 0;
        }

        $destFilename = \sprintf("%s/%s", $destination, \basename($filename));

        if (\file_exists($destFilename)) {

            // Attempt a sha1 over both the file content, and do not fail
            // or proceed if files have the same type and sha1 sum.
            // @todo this should not be a default behaviour, a user might
            //   want to let same files to be uploaded.
            if (self::unsafeIsDuplicateOf($filename, $destFilename)) {
                return $destFilename;
            }

            if ($flags & self::MOVE_CONFLICT_OVERWRITE) {
                // Do nothing, we will overwrite the file
            } else if ($flags & self::MOVE_CONFLICT_RENAME) {
                $destFilename = $this->unsafeDeduplicate($destFilename);
            } else {
                throw new IOException(\sprintf("Cannot move '%s' to '%s': file exists", $filename, $destFilename));
            }
        }

        $filesystem->rename($filename, $destFilename, true);

        return $destFilename;
    }

    /**
     * Move a file to another folder.
     *
     * @param string $source
     *   Source file URI or absolute path
     * @param string $destination
     *   Destination folder, if file with the same name already exists
     * @param int $options
     *   Bitflags (constants of this class) that will alter this function
     *   behavior.
     *
     * @return string
     *   The new file URI (and not absolute path) even if file was not moved.     */
    public function rename(string $source, string $destination,
        int $flags = 0, ?string $strategy = null, ?int $mode = null): string
    {
        return $this->getURI(
            $this->unsafeRename(
                $this->getAbsolutePath($source),
                $this->getAbsolutePath($destination),
                $flags, $strategy
            )
        );
    }

    /**
     * Move a file to another folder, do nothing if file is already within
     * the given destination directory.
     *
     * @param string $sourceUri
     *   Source file URI or absolute path
     * @param string $destination
     *   Destination folder, if file with the same name already exists
     * @param int $options
     *   Bitflags (constants of this class) that will alter this function
     *   behavior.
     *
     * @return string
     *   The new file URI (and not absolute path) even if file was not moved.
     */
    public function renameIfNotWithin(string $source, string $destination,
        int $flags = 0, ?string $strategy = null): string
    {
        $sourcePath = $this->getAbsolutePath($source);
        $destinationPath = $this->getAbsolutePath($destination);

        if ($this->unsafeIsPathWithin($sourcePath, $destinationPath)) {
            return $this->getURI($sourcePath);
        }

        return $this->getURI(
            $this->unsafeRename($sourcePath, $destinationPath, $flags, $strategy)
        );
    }

    /**
     * Get default chmod for directories
     */
    public function getDefaultModeForDir(): int
    {
        // @todo make this configurable at runtime, or at least
        //   make it honor the current umask env setting
        return self::MODE_DIR;
    }

    /**
     * Internal implementation of mkdir().
     */
    private function unsafeMkdir(string $directory, ?int $mode = null): void
    {
        (new Filesystem())->mkdir($directory, $mode ?? $this->getDefaultModeForDir());
    }

    /**
     * Create directory
     */
    public function mkdir(string $directory, ?int $mode = null): void
    {
        $this->unsafeMkdir(
            $this->getAbsolutePath($directory),
            $mode
        );
    }

    /**
     * Internal implementation of copy().
     */
    private function unsafeCopy(string $source, string $destination): void
    {
        // @todo Symfony implementation never uses copy() which makes it
        //   inneficient when dealing with local filesystem, we should
        //   bypass it when files are local.
        (new Filesystem())->copy($source, $destination);
    }

    /**
     * Copy file to destination, it will always overwrite files according to
     * \Symfony\Component\Filesystem\Filesystem::copy() own overwrite strategy
     * which may vary depending upon if the file is local or distant.
     */
    public function copy(string $source, string $destination): void
    {
        $this->unsafeCopy(
            $this->getAbsolutePath($source),
            $this->getAbsolutePath($destination)
        );
    }

    /**
     * Get given filename or URI relative path to given directory
     */
    public function getRelativePathFrom(string $uri, string $directory): ?string
    {
        $uri = $this->getAbsolutePath($uri);
        $directory = $this->getAbsolutePath($directory);
        $length = \strlen($directory);

        if (\substr($uri, 0, $length) !== $directory) {
            return null;
        }

        return \ltrim(\substr($uri, $length), '/');
    }

    /**
     * From a filename or URI return the webroot relative URI
     */
    public function getFileUrl(string $filenameOrUri): ?string
    {
        if ($this->webroot) {
            return $this->getRelativePathFrom($filenameOrUri, $this->webroot);
        }
        return null;
    }

    /**
     * Get relative URI of file, relative to its working directory.
     *
     * If an URI outside of a known scheme, an absolute URI, a relative URI
     * without scheme, or an unknown scheme is given, it will be returned as-is
     * but just normalized.
     */
    public function getAbsolutePath(string $uri): string
    {
        if ('://' === \substr($uri, -3)) {
            if (($scheme = \substr($uri, 0, -3)) && isset($this->knownSchemes[$scheme])) {
                return $this->getWorkingDirectory($scheme);
            }
        }

        $uri = self::normalizePath($uri);

        if ($identity = $this->unsafeIdentify($uri)) {
            return $identity->getAbsolutePath();
        }

        return $uri;
  }

    /**
     * Get URI from filename.
     *
     * If an URI outside of a known scheme, an absolute URI, a relative URI
     * without scheme, or an unknown scheme is given, it will be returned as-is
     * but just normalized.
     */
    public function getURI(string $filename): string
    {
        $filename = self::normalizePath($filename);

        if ($identity = $this->unsafeIdentify($filename)) {
            return (string)$identity;
        }

        return $filename;
    }

    /**
     * Create file instance from given URI
     */
    public function createFile(string $uri, bool $checkpath = true): File
    {
        return new File($this->getAbsolutePath($uri), $checkpath);
    }
}

/**
 * Represents a file with scheme match
 */
final class SchemeURI
{
    private $relativeURI;
    private $scheme;
    private $workingDirectory;

    public function __construct(string $scheme, string $relativeURI, string $workingDirectory)
    {
        $this->relativeURI = $relativeURI;
        $this->scheme = $scheme;
        $this->workingDirectory = $workingDirectory;
    }

    /**
     * Get URI scheme relative path
     */
    public function getRelativePath(): string
    {
        return $this->relativeURI;
    }

    /**
     * Get scheme working directory
     */
    public function getWorkingDirectory(): string
    {
        return $this->workingDirectory;
    }

    /**
     * Get URI scheme
     */
    public function getScheme(): string
    {
        return $this->scheme;
    }

    /**
     * Get URI absolute path
     */
    public function getAbsolutePath(): string
    {
        return \sprintf("%s/%s", $this->workingDirectory, $this->relativeURI);
    }

    /**
     * URI representation with scheme
     */
    public function __toString(): string
    {
        return \sprintf("%s://%s", $this->scheme, $this->relativeURI);
    }
}
