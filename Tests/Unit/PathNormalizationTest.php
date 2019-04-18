<?php

declare(strict_types=1);

namespace MakinaCorpus\FilechunkBundle\Tests\Unit;

use MakinaCorpus\FilechunkBundle\FileManager;
use PHPUnit\Framework\TestCase;

/**
 * Tests path normalization
 */
final class PathNormalizationTest extends TestCase
{
    public function getPathNormalizationMap()
    {
        return [
            // Tests with '..'
            ['a/b/..', 'a'],
            ['https://a/b/../', 'https://a'],
            ['/a/b/c/d/../e/f', '/a/b/c/e/f'],
            ['a/b/c/../../e/f', 'a/e/f'],
            ['ftp://a/../b/../c/../e/f', 'ftp://e/f'],
            ['a../b/c../d..e/', 'a../b/c../d..e'],
            ['../c/d', '../c/d'],
            // Windows various
            // ['file://C:\\Windows\\system32', 'file://C:/Windows/system32'],
            // ['C:\\Windows\\system32', 'C:/Windows/system32'],
            // ['Windows\\drivers/system32', 'C:/Windows/system32'],
            // With multiple '/'
            ['/a/b/////c/d/../e/f', '/a/b/c/e/f'],
            ['file:////a/b/c//../..//e/f', 'file:///a/e/f'],
            ['////a/../b/../c//../e/f', '/e/f'],
            ['a../b//c../d..e/', 'a../b/c../d..e'],
            ['../c////d', '../c/d'],
            // With dots
            ['a/b/./././..', 'a'],
            ['a/.b/./../', 'a'],
            ['/a/b/.c/d/../e/f', '/a/b/.c/e/f'],
            ['.a/./b/c/.././../e./f', '.a/e./f'],
            // Special cases
            ['/', '/'],
            ['.', '.'],
            ['..', '..'],
            ['./', '.'],
            ['../', '..'],
        ];
    }

    /**
     * @dataProvider getPathNormalizationMap
     */
    public function testNormalizePath($path, $expected)
    {
        $this->assertSame($expected, FileManager::normalizePath($path));
    }
}
