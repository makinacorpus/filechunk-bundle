<?php

declare(strict_types=1);

namespace MakinaCorpus\FilechunkBundle\FileSessionHandler;

use MakinaCorpus\Files\FileManager;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * This implementation is here for unit testing.
 */
final class SessionFileSessionHandler extends AbstractFileSessionHandler
{
    private SessionInterface $session;

    public function __construct(FileManager $fileManager, SessionInterface $session)
    {
        parent::__construct($fileManager);

        $this->session = $session;
    }

    /**
     * {@inheritdoc}
     */
    protected function getSession(): SessionInterface
    {
        return $this->session;
    }
}
