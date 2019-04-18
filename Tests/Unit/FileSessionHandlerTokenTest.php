<?php

declare(strict_types=1);

namespace MakinaCorpus\FilechunkBundle\Tests\Unit;

use MakinaCorpus\FilechunkBundle\FileSessionHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Field config test
 */
final class FileSessionHandlerTokenTest extends TestCase
{
    private function createSession(): SessionInterface
    {
        return new Session(
            new MockArraySessionStorage('coucou')
        );
    }

    private function createFileSessionHandler(): FileSessionHandler
    {
        return new FileSessionHandler(
            $this->createSession()
        );
    }

    public function testTokenIsValid()
    {
        $session = $this->createFileSessionHandler();

        $this->assertFalse($session->isTokenValid("this is a non possible token"));

        $previousToken = $session->getCurrentToken();
        $this->assertTrue($session->isTokenValid($previousToken));

        $newToken = $session->regenerateToken();
        $this->assertTrue($session->isTokenValid($newToken));

        $this->assertFalse($session->isTokenValid($previousToken));
    }
}
