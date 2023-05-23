<?php

declare(strict_types=1);

namespace MakinaCorpus\FilechunkBundle\Tests\Unit;

use MakinaCorpus\FilechunkBundle\FieldConfig;
use MakinaCorpus\FilechunkBundle\FileSessionHandler;
use MakinaCorpus\FilechunkBundle\FileSessionHandler\SessionFileSessionHandler;
use MakinaCorpus\Files\FileManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Field config test
 */
final class FieldConfigTest extends TestCase
{
    private function createSession(): SessionInterface
    {
        return new Session(
            new MockArraySessionStorage('coucou')
        );
    }

    private function createFileManager(): FileManager
    {
        return new FileManager([
            'test' => __DIR__,
            FileManager::SCHEME_PRIVATE => '/tmp/private',
            FileManager::SCHEME_PUBLIC => '/tmp/public',
            FileManager::SCHEME_UPLOAD => '/tmp/upload',
            FileManager::SCHEME_TEMPORARY => '/tmp',
        ]);
    }

    private function createFileSessionHandler(): FileSessionHandler
    {
        return new SessionFileSessionHandler(
            $this->createFileManager(),
            $this->createSession()
        );
    }

    public function testGettersAndSetters()
    {
        $config = FieldConfig::fromArray('foo', []);
        $this->assertSame('foo', $config->getName());
        $this->assertNull($config->getMaxCount());
        $this->assertNull($config->getMaxSize());
        $this->assertTrue($config->isMimeTypeAllowed('foo/bar'));
        $this->assertNull($config->getNamingStrategy());
        $this->assertNull($config->getTargetDirectory());

        $config = FieldConfig::fromArray('bar', [
            'maxcount' => "7",
            'maxsize' => 13,
            'mimetypes' => ['image/webp'],
            'naming_strategy' => 'non_existing_strategy',
            'target_directory' => '/tmp',
        ]);
        $this->assertSame('bar', $config->getName());
        $this->assertSame(7, $config->getMaxCount());
        $this->assertSame(13, $config->getMaxSize());
        $this->assertTrue($config->isMimeTypeAllowed('image/webp'));
        $this->assertFalse($config->isMimeTypeAllowed('foo/bar'));
        $this->assertSame('non_existing_strategy', $config->getNamingStrategy());
        $this->assertSame('/tmp', $config->getTargetDirectory());
    }

    public function testInvalidSizeRaiseError()
    {
        $this->expectException(\InvalidArgumentException::class);
        FieldConfig::fromArray('foo', ['maxsize' => new \stdClass()]);
    }

    public function testInvalidMaxCountRaiseError()
    {
        $this->expectException(\InvalidArgumentException::class);
        FieldConfig::fromArray('foo', ['maxcount' => -1]);
    }

    public function testInvalidTargetDirectorRaiseError()
    {
        $this->expectException(\InvalidArgumentException::class);
        FieldConfig::fromArray('foo', ['target_directory' => new \stdClass()]);
    }

    public function testGetSetFieldConfig()
    {
        $session = $this->createFileSessionHandler();

        $this->assertNull($session->getFieldConfig('my_field'));

        $session->addFieldConfig($config = FieldConfig::fromArray('my_field', []));
        $this->assertSame($config, $session->getFieldConfig('my_field'));
    }

    public function testGlobalFieldConfig()
    {
        $session = $this->createFileSessionHandler();

        $this->assertNull($session->getFieldConfig('some_field'));

        $config = FieldConfig::fromArray('some_field', []);

        $session->addGlobalFieldConfig($config);
        $this->assertSame($config, $session->getGlobalFieldConfig('some_field'));
        $this->assertSame($config, $session->getFieldConfig('some_field'));
    }

    public function testSessionFieldConfigOverridesGlobal()
    {
        $session = $this->createFileSessionHandler();

        $this->assertNull($session->getFieldConfig('some_field'));

        $config = FieldConfig::fromArray('some_field', []);
        $configLocal = FieldConfig::fromArray('some_field', ['maxsize' => 7]);

        $session->addGlobalFieldConfig($config);
        $session->addFieldConfig($configLocal);

        $this->assertSame($config, $session->getGlobalFieldConfig('some_field'));
        $this->assertSame($configLocal, $session->getFieldConfig('some_field'));
    }
}
