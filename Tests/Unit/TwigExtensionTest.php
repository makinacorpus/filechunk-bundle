<?php

declare(strict_types=1);

namespace MakinaCorpus\FilechunkBundle\Tests\Unit;

use MakinaCorpus\FilechunkBundle\FileManager;
use MakinaCorpus\FilechunkBundle\Twig\FilechunkExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * File manager tests
 */
final class TwigExtensionTest extends TestCase
{
    private function createTwigExtension(): FilechunkExtension
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('http://perdu.com/'));

        return new FilechunkExtension(
            new FileManager([
                FileManager::SCHEME_PRIVATE => '/some/path/../private//',
                FileManager::SCHEME_PUBLIC => '/var/www/html/',
                FileManager::SCHEME_UPLOAD => '/tmp/upload',
                FileManager::SCHEME_TEMPORARY => '/tmp',
            ], '/var/www/'),
            $requestStack
        );
    }

    public function testGetFileInternalUri()
    {
        $ext = $this->createTwigExtension();

        $this->assertSame('public://pouet.png', $ext->getFileInternalUri('/var/www/html/pouet.png'));
        $this->assertSame('public://pouet.png', $ext->getFileInternalUri('public://pouet.png'));
    }

    public function testGetFileAbsolutePath()
    {
        $ext = $this->createTwigExtension();

        $this->assertSame('/var/www/html/pouet.png', $ext->getFileAbsolutePath('/var/www/html/pouet.png'));
        $this->assertSame('/var/www/html/pouet.png', $ext->getFileAbsolutePath('public://pouet.png'));
    }

    public function testGetFileUrl()
    {
        $ext = $this->createTwigExtension();

        $this->assertSame('/html/pouet.png', $ext->getFileUrl('/var/www/html/pouet.png'));
        $this->assertSame('/html/pouet.png', $ext->getFileUrl('public://pouet.png'));
    }

    public function testGetFileUrlAbsolute()
    {
        $ext = $this->createTwigExtension();

        $this->assertSame('http://perdu.com/html/pouet.png', $ext->getFileUrl('/var/www/html/pouet.png', true));
        $this->assertSame('http://perdu.com/html/pouet.png', $ext->getFileUrl('public://pouet.png', true));
    }

    public function testGetFileUrlOutsideOfWebrootReturnsError()
    {
        $ext = $this->createTwigExtension();

        $this->assertSame(FilechunkExtension::ERROR_PATH, $ext->getFileUrl('private://pouet.png'));
        $this->assertSame(FilechunkExtension::ERROR_PATH, $ext->getFileUrl('/tmp/some/file.png'));
    }
}
