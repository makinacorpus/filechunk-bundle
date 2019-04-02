<?php

declare(strict_types=1);

namespace MakinaCorpus\FilechunkBundle\Tests\Unit;

use MakinaCorpus\FilechunkBundle\FileManager;
use MakinaCorpus\FilechunkBundle\SchemeURI;
use PHPUnit\Framework\TestCase;

/**
 * File manager tests
 */
final class FileManagerTest extends TestCase
{
    private function createFileManager(): FileManager
    {
        return new FileManager([
            FileManager::SCHEME_PRIVATE => '/some/path/../private//',
            FileManager::SCHEME_PUBLIC => '/var/www/html/',
            FileManager::SCHEME_UPLOAD => '/tmp/upload',
            FileManager::SCHEME_TEMPORARY => '/tmp',
        ]);
    }

    public function testGetWorkingDirectory()
    {
        $manager = $this->createFileManager();

        $this->assertSame('/some/private', $manager->getWorkingDirectory(FileManager::SCHEME_PRIVATE));
    }

    public function testGetWorkingDirectoryFailsWithUnknownScheme()
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->createFileManager()->getWorkingDirectory('unknownone');
    }

    public function testIsPathWithin()
    {
        $manager = $this->createFileManager();

        $this->assertFalse($manager->isPathWithin('/foo/../bar/../baz/test', '/foo/bar/../baz'));
        $this->assertTrue($manager->isPathWithin('/foo/baz/test', '/foo/bar/../baz'));
        $this->assertTrue($manager->isPathWithin('/var/www/html/bar/pouet', 'public://bar'));
        $this->assertTrue($manager->isPathWithin('public://bar/pouet', '/var/www/html/bar'));
        $this->assertTrue($manager->isPathWithin('public://bar/pouet', 'public://bar'));
    }

    public function testGetRelativePathFrom()
    {
        $manager = $this->createFileManager();

        $this->assertNull($manager->getRelativePathFrom('/foo/../bar/../baz/test', '/foo/bar/../baz'));
        $this->assertSame('test', $manager->getRelativePathFrom('/foo/baz/test', '/foo/bar/../baz'));
        $this->assertSame('baz/pouet', $manager->getRelativePathFrom('/var/www/html/bar/baz/pouet', 'public://bar'));
        $this->assertSame('baz/pouet', $manager->getRelativePathFrom('public://bar/baz/pouet', '/var/www/html/bar'));
        $this->assertSame('baz/pouet', $manager->getRelativePathFrom('public://bar/baz/pouet', 'public://bar'));
    }

    public function testIdentifyNestedSchemeDeambiguation()
    {
        $manager = $this->createFileManager();

        $identity = $manager->identify('/tmp/upload/file.png');
        $this->assertInstanceOf(SchemeURI::class, $identity);
        $this->assertSame(FileManager::SCHEME_UPLOAD, $identity->getScheme());

        $identity = $manager->identify('/tmp/pouf/file.png');
        $this->assertInstanceOf(SchemeURI::class, $identity);
        $this->assertSame(FileManager::SCHEME_TEMPORARY, $identity->getScheme());
    }

    public function testIdentifyWithAbsolutePathWithUnknownScheme()
    {
        $manager = $this->createFileManager();

        $identity = $manager->identify('/some/path/file.png');
        $this->assertNull($identity);
    }

    public function testIdentifyWithAbsolutePathInKnownScheme()
    {
        $manager = $this->createFileManager();

        $identity = $manager->identify('/some/private/oups/../file/is/here.png');
        $this->assertInstanceOf(SchemeURI::class, $identity);
        $this->assertSame(FileManager::SCHEME_PRIVATE, $identity->getScheme());
        $this->assertSame('/some/private/file/is/here.png', $identity->getAbsolutePath());
        $this->assertSame('file/is/here.png', $identity->getRelativePath());
        $this->assertSame('/some/private', $identity->getWorkingDirectory());
        $this->assertSame('private://file/is/here.png', (string)$identity);
    }

    public function testIdentifyWithAbsolutePathWithLocalSchemeInKnownScheme()
    {
        $manager = $this->createFileManager();

        $identity = $manager->identify('file:///some/private/file/is/here.png');
        $this->assertInstanceOf(SchemeURI::class, $identity);
        $this->assertSame(FileManager::SCHEME_PRIVATE, $identity->getScheme());
        $this->assertSame('/some/private/file/is/here.png', $identity->getAbsolutePath());
        $this->assertSame('file/is/here.png', $identity->getRelativePath());
        $this->assertSame('/some/private', $identity->getWorkingDirectory());
        $this->assertSame('private://file/is/here.png', (string)$identity);
    }

    public function testIdentifyWithUnknownScheme()
    {
        $manager = $this->createFileManager();

        $identity = $manager->identify('ftp://192.168.1.32:2121/some/path/file.png');
        $this->assertNull($identity);
    }

    public function testIdentifyWithScheme()
    {
        $manager = $this->createFileManager();

        $identity = $manager->identify('public://article/212/thumbnail.jpg');
        $this->assertInstanceOf(SchemeURI::class, $identity);
        $this->assertSame(FileManager::SCHEME_PUBLIC, $identity->getScheme());
        $this->assertSame('/var/www/html/article/212/thumbnail.jpg', $identity->getAbsolutePath());
        $this->assertSame('article/212/thumbnail.jpg', $identity->getRelativePath());
        $this->assertSame('/var/www/html', $identity->getWorkingDirectory());
        $this->assertSame('public://article/212/thumbnail.jpg', (string)$identity);
    }
}
