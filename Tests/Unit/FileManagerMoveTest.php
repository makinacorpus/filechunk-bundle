<?php

declare(strict_types=1);

namespace MakinaCorpus\FilechunkBundle\Tests\Unit;

use MakinaCorpus\FilechunkBundle\FileManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOException;

/**
 * File manager tests for the rename and alternatives functions
 */
final class FileManagerMoveTest extends TestCase
{
    private function createFileManager(): FileManager
    {
        $manager = new FileManager([
            'test' => __DIR__,
            FileManager::SCHEME_PRIVATE => '/tmp/private',
            FileManager::SCHEME_PUBLIC => '/tmp/public',
            FileManager::SCHEME_UPLOAD => '/tmp/upload',
            FileManager::SCHEME_TEMPORARY => '/tmp',
        ]);

        // @todo using setup() would be better here
        // Prepare test case.
        // Avoid conflicts with previous tests.
        $filesystem = new Filesystem();
        $filesystem->remove($manager->getAbsolutePath('temporary://destination'));
        $filesystem->remove($manager->getAbsolutePath('temporary://source'));

        // Create test files.
        $manager->mkdir('temporary://destination');
        $manager->mkdir('temporary://destination/alt');
        $manager->mkdir('temporary://source');
        $manager->copy('test://cat1200.jpg', 'temporary://destination/file.jpg');
        $manager->copy('test://cat800.jpg', 'temporary://source/file.jpg');

        return $manager;
    }

    public function testIfRenameWithin()
    {
        $manager = $this->createFileManager();

        // File moves
        $this->assertSame(
            'temporary://destination/alt/file.jpg',
            $manager->renameIfNotWithin('temporary://source/file.jpg', 'temporary://destination/alt')
        );

        // File does not move
        $this->assertSame(
            'temporary://destination/alt/file.jpg',
            $manager->renameIfNotWithin('temporary://destination/alt/file.jpg', 'temporary://destination')
        );
    }

    public function testIfRenameWithinDoesNotMove()
    {
        $manager = $this->createFileManager();

        $this->assertSame(
            'temporary://destination/alt/file.jpg',
            $manager->renameIfNotWithin('temporary://source/file.jpg', 'temporary://destination/alt')
        );
    }

    public function testDeduplicateNameWithExt()
    {
        $manager = $this->createFileManager();

        $this->assertSame('test://cat1200_2.jpg', $manager->deduplicate('test://cat1200.jpg'));
    }

    public function testDeduplicateNameWithoutExt()
    {
        $manager = $this->createFileManager();

        $this->assertSame('test://cat1200_1', $manager->deduplicate('test://cat1200'));
    }

    public function testRenameWithInvalidDirectoryStrategy()
    {
        $manager = $this->createFileManager();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageRegExp('/directory strategy/');
        $manager->rename('temporary://source/file.jpg', 'temporary://destination', 0, 'foo');
    }

    public function testRenameWithDateDirectoryStrategy()
    {
        $manager = $this->createFileManager();

        $date = new \DateTime();
        $result = $manager->rename('temporary://source/file.jpg', 'temporary://destination', 0, 'date');
        $this->assertSame(
            \sprintf(
                'temporary://destination/%s/%s/%s/file.jpg',
                $date->format('Y'), $date->format('m'), $date->format('d')
            ),
            $result
        );
    }

    public function testRenameWithDatetimeDirectoryStrategy()
    {
        $manager = $this->createFileManager();

        $date = new \DateTime();
        $result = $manager->rename('temporary://source/file.jpg', 'temporary://destination', 0, 'datetime');
        $this->assertSame(
            \sprintf(
                'temporary://destination/%s/%s/%s/%s/%s/file.jpg',
                $date->format('Y'), $date->format('m'), $date->format('d'),
                $date->format('h'), $date->format('i')
            ),
            $result
        );
    }

    public function testRenameWithNonExistingFileRaiseError()
    {
        $manager = $this->createFileManager();

        $this->expectException(IOException::class);
        $this->expectExceptionMessageRegExp('/does not exist/');
        $manager->rename('temporary://source/non-existing-file.jpg', 'temporary://destination');
    }

    public function testRenameWithOverwriteStrategy()
    {
        $manager = $this->createFileManager();

        $this->assertFalse($manager->isDuplicateOf('temporary://source/file.jpg', 'temporary://destination/file.jpg'));
        $manager->copy('temporary://source/file.jpg', 'temporary://source/file-reference.jpg');

        $result = $manager->rename(
            'temporary://source/file.jpg', 'temporary://destination',
            FileManager::MOVE_CONFLICT_OVERWRITE
        );

        $this->assertSame('temporary://destination/file.jpg', $result);
        $this->assertTrue($manager->isDuplicateOf('temporary://source/file-reference.jpg', 'temporary://destination/file.jpg'));
    }

    public function testRenameWithRenameStrategy()
    {
        $manager = $this->createFileManager();

        $result = $manager->rename(
            'temporary://source/file.jpg', 'temporary://destination',
            FileManager::MOVE_CONFLICT_RENAME
        );

        $this->assertSame('temporary://destination/file_1.jpg', $result);
    }

    public function testRenameWithErrorStrategy()
    {
        $manager = $this->createFileManager();

        $this->expectException(IOException::class);
        $this->expectExceptionMessageRegExp('/file exists/');
        $manager->rename('temporary://source/file.jpg', 'temporary://destination');
    }
}
