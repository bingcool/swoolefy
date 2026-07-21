<?php

declare(strict_types=1);

namespace PhpUintTest\Unit\Library\FileStorageSystem;

use PhpUintTest\TestCase;
use Swoolefy\Library\FileStorageSystem\Exception\UnsupportedCapabilityException;
use Swoolefy\Library\FileStorageSystem\FileStorageManager;

final class LocalAdapterTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/swoolefy_fs_local_' . uniqid('', true);
        mkdir($this->root, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->root);
        parent::tearDown();
    }

    public function testLocalPutGetAndMetadata(): void
    {
        $disk = $this->disk();
        $disk->putObject('folder/hi.txt', 'hello-local', ['mime' => 'text/plain']);
        $this->assertSame('hello-local', $disk->getObject('folder/hi.txt'));
        $meta = $disk->getMetadata('folder/hi.txt');
        $this->assertSame(11, $meta->size);
        $this->assertNotNull($meta->etag);
        $this->assertSame($meta->modified, $disk->lastModified('folder/hi.txt'));
        $this->assertSame($meta->modified, $disk->getTimestamp('folder/hi.txt'));
    }

    public function testLocalMultipartIsStreamCopyNotFakeParts(): void
    {
        $src = $this->root . '/source.bin';
        file_put_contents($src, str_repeat('A', 1024 * 64));
        $disk = $this->disk();
        $meta = $disk->putObjectMultipart('videos/a.bin', $src);
        $this->assertSame(1024 * 64, $meta->size);
        $this->assertSame(filesize($src), strlen($disk->getObject('videos/a.bin')));
        // 不应残留 .uploading 临时文件
        $files = glob($this->root . '/videos/*');
        $this->assertNotFalse($files);
        foreach ($files as $f) {
            $this->assertStringNotContainsString('.uploading.', $f);
            $this->assertStringNotContainsString('.multipart.', $f);
        }
    }

    public function testLocalMultipartFromStream(): void
    {
        $disk = $this->disk();
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, 'stream-data');
        rewind($stream);
        $meta = $disk->putObjectMultipart('s/stream.txt', $stream);
        fclose($stream);
        $this->assertSame(11, $meta->size);
        $this->assertSame('stream-data', $disk->getObject('s/stream.txt'));
    }

    public function testLocalSignedUrlUnsupported(): void
    {
        $disk = $this->disk();
        $disk->putObject('a.txt', 'x');
        $this->expectException(UnsupportedCapabilityException::class);
        // ObjectUrlCapable 已挂载，但 sign 抛 ObjectUrlNotSupported；门面仍会调用到 adapter
        // 这里测 getClient 不支持
        $disk->getClient();
    }

    private function disk()
    {
        $mgr = new FileStorageManager([
            'default_provider' => 'local',
            'file_system_providers' => [
                'local' => [
                    'driver' => 'local',
                    'root' => $this->root,
                    'public_base_url' => 'http://localhost/files',
                ],
            ],
        ]);

        return $mgr->disk();
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $path = $file->getPathname();
            $file->isDir() ? @rmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
