<?php

declare(strict_types=1);

namespace MakinaCorpus\FilechunkBundle\FileSessionHandler;

use MakinaCorpus\Files\FileManager;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * When working during production runtime, this is the implementation to use
 * which will always attempt to dynamically resolve the session using the
 * current request stack.
 */
final class RequestStackFileSessionHandler extends AbstractFileSessionHandler
{
    private RequestStack $requestStack;

    public function __construct(FileManager $fileManager, RequestStack $requestStack)
    {
        parent::__construct($fileManager);

        $this->requestStack = $requestStack;
    }

    /**
     * {@inheritdoc}
     */
    protected function getSession(): SessionInterface
    {
        return $this->requestStack->getSession();
    }
}
