<?php

declare(strict_types=1);

namespace MakinaCorpus\FilechunkBundle\Twig;

use MakinaCorpus\FilechunkBundle\FileManager;
use Twig\TwigFunction;
use Twig\Extension\AbstractExtension;
use Symfony\Component\HttpFoundation\RequestStack;

final class FilechunkExtension extends AbstractExtension
{
    const ERROR_PATH = '#error';

    private $fileManager;
    private $requestStack;

    /**
     * Default constructor
     */
    public function __construct(FileManager $fileManager, RequestStack $requestStack)
    {
        $this->fileManager = $fileManager;
        $this->requestStack = $requestStack;
    }

    /**
     * {@inheritdoc}
     *
     * @codeCoverageIgnore
     */
    public function getFunctions()
    {
        return [
            new TwigFunction('file_absolute_path', [$this, 'getFileAbsolutePath']),
            new TwigFunction('file_internal_uri', [$this, 'getFileInternalUri']),
            new TwigFunction('file_url', [$this, 'getFileUrl']),
        ];
    }

    /**
     * From a filename or URI return the absolute URL on server
     */
    public function getFileAbsolutePath(string $filenameOrUri): string
    {
        return $this->fileManager->getAbsolutePath($filenameOrUri);
    }

    /**
     * From a filename or URI return the internal URI
     */
    public function getFileInternalUri(string $filenameOrUri): string
    {
        return $this->fileManager->getURI($filenameOrUri);
    }

    /**
     * From a filename or URI return the webroot relative URI
     */
    public function getFileUrl(string $filenameOrUri, bool $absolute = false): string
    {
        $relativePath = $this->fileManager->getFileUrl($filenameOrUri);

        if (!$relativePath) {
            return self::ERROR_PATH;
        }

        if ($absolute) {
            if ($request = $this->requestStack->getMasterRequest()) {
                if ($basePath = $request->getBasePath()) {
                    return $request->getSchemeAndHttpHost().'/'.$basePath.'/'.$relativePath;
                }
                return $request->getSchemeAndHttpHost().'/'.$relativePath;
            }
        }

        return '/'.$relativePath;
    }
}
