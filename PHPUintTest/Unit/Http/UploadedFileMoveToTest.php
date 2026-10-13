<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Http;

use PHPUintTest\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Swoolefy\Http\UploadedFile;
use Swoolefy\Http\UploadException;

final class UploadedFileMoveToTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir() . '/swoolefy_upload_' . bin2hex(random_bytes(4));
        mkdir($this->base . '/store', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->base);
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function traversalNames(): iterable
    {
        yield 'parent dir' => ['../evil.php', 'evil.php'];
        yield 'deep parent' => ['../../../../tmp/evil.php', 'evil.php'];
        yield 'subdir' => ['sub/dir/a.txt', 'a.txt'];
        yield 'windows separators' => ['..\\..\\evil.php', 'evil.php'];
        yield 'absolute' => ['/etc/cron.d/evil', 'evil'];
    }

    #[DataProvider('traversalNames')]
    public function testFilenameIsReducedToBasenameInsideDirectory(string $filename, string $expected): void
    {
        $target = $this->upload()->moveTo($this->base . '/store', $filename);

        $this->assertSame($this->base . '/store' . DIRECTORY_SEPARATOR . $expected, $target);
        $this->assertFileExists($target);
        $this->assertFileDoesNotExist($this->base . '/evil.php');
    }

    public function testDotNamesAreRejected(): void
    {
        foreach (['', '.', '..', '../', "a\0.php"] as $name) {
            try {
                $this->upload()->moveTo($this->base . '/store', $name);
                $this->fail('should reject ' . json_encode($name));
            } catch (UploadException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testGeneratedNameIgnoresClientPath(): void
    {
        $target = $this->upload('../../evil.php')->moveTo($this->base . '/store');

        $this->assertSame($this->base . '/store', dirname($target));
        $this->assertMatchesRegularExpression('/^evil_[0-9a-f]{16}\.php$/', basename($target));
    }

    private function upload(string $clientName = 'a.txt'): UploadedFile
    {
        $tmp = $this->base . '/tmp_' . bin2hex(random_bytes(4));
        file_put_contents($tmp, 'data');

        return new UploadedFile($clientName, 'text/plain', $tmp, UPLOAD_ERR_OK, 4);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
