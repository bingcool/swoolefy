<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Library\FileStorageSystem;

use PHPUintTest\TestCase;
use Swoolefy\Library\FileStorageSystem\Exception\InvalidObjectPathException;
use Swoolefy\Library\FileStorageSystem\Support\PathNormalizer;

final class PathNormalizerTest extends TestCase
{
    public function testNormalizesDotsAndSlashes(): void
    {
        $this->assertSame('abc/a.txt', PathNormalizer::normalize('/abc//def/../a.txt'));
        $this->assertSame('foo/bar/file.txt', PathNormalizer::normalize('\\foo\\bar\\file.txt'));
        $this->assertSame('folder/x.PNG', PathNormalizer::normalize('  folder/x.PNG  '));
        $this->assertSame('a/b', PathNormalizer::normalize('./a/./b'));
    }

    public function testRejectsEscapeAndAbsolute(): void
    {
        $this->expectException(InvalidObjectPathException::class);
        PathNormalizer::normalize('../secret');
    }

    public function testRejectsUrl(): void
    {
        $this->expectException(InvalidObjectPathException::class);
        PathNormalizer::normalize('http://example.com/a.txt');
    }

    public function testRejectsEmptyAfterNormalize(): void
    {
        $this->expectException(InvalidObjectPathException::class);
        PathNormalizer::normalize('/./');
    }
}
