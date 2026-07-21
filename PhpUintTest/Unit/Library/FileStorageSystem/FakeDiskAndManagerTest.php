<?php

declare(strict_types=1);

namespace PhpUintTest\Unit\Library\FileStorageSystem;

use PhpUintTest\TestCase;
use Swoolefy\Library\FileStorageSystem\Contracts\CloudClientAwareInterface;
use Swoolefy\Library\FileStorageSystem\Contracts\MultipartCapableInterface;
use Swoolefy\Library\FileStorageSystem\Exception\InvalidObjectPathException;
use Swoolefy\Library\FileStorageSystem\Exception\ObjectNotFoundException;
use Swoolefy\Library\FileStorageSystem\Exception\UnsupportedCapabilityException;
use Swoolefy\Library\FileStorageSystem\FileStorage;
use Swoolefy\Library\FileStorageSystem\FileStorageManager;

final class FakeDiskAndManagerTest extends TestCase
{
    protected function tearDown(): void
    {
        FileStorage::clearManager();
        parent::tearDown();
    }

    public function testFakePutGetMetadataAndMultipart(): void
    {
        $mgr = new FileStorageManager([
            'default_provider' => 'fake',
            'file_system_providers' => [
                'fake' => ['driver' => 'fake'],
            ],
        ]);
        $disk = $mgr->disk();

        $disk->putObject('docs/a.txt', 'hello', ['mime' => 'text/plain']);
        $this->assertTrue($disk->fileExists('docs/a.txt'));
        $this->assertSame('hello', $disk->getObject('docs/a.txt'));

        $meta = $disk->getMetadata('docs/a.txt');
        $this->assertSame(5, $meta->size);
        $this->assertSame('text/plain', $meta->mime);
        $this->assertNotNull($meta->etag);
        $this->assertNotNull($meta->checksum);
        $this->assertNotNull($meta->created);
        $this->assertNotNull($meta->modified);

        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, 'world-bytes');
        rewind($stream);
        $meta2 = $disk->putObjectMultipart('docs/b.bin', $stream);
        fclose($stream);
        $this->assertSame(11, $meta2->size);
        $this->assertSame('world-bytes', $disk->getObject('docs/b.bin'));

        $this->assertTrue($disk->supports(MultipartCapableInterface::class));
        $this->assertFalse($disk->supports(CloudClientAwareInterface::class));

        $this->expectException(UnsupportedCapabilityException::class);
        $disk->getClient();
    }

    public function testManagerCachesDiskInstance(): void
    {
        $mgr = new FileStorageManager([
            'default_provider' => 'fake',
            'file_system_providers' => [
                'fake' => ['driver' => 'fake'],
            ],
        ]);
        $a = $mgr->disk('fake');
        $b = $mgr->disk('fake');
        $this->assertSame($a, $b);
    }

    public function testPathNormalizedOnFacade(): void
    {
        $mgr = new FileStorageManager([
            'default_provider' => 'fake',
            'file_system_providers' => [
                'fake' => ['driver' => 'fake'],
            ],
        ]);
        $disk = $mgr->disk();
        $disk->putObject('/abc//def/../a.txt', 'x');
        $this->assertTrue($disk->fileExists('abc/a.txt'));
        $this->assertSame('x', $disk->getObject('abc/a.txt'));
    }

    public function testMissingObjectThrows(): void
    {
        $mgr = new FileStorageManager([
            'default_provider' => 'fake',
            'file_system_providers' => [
                'fake' => ['driver' => 'fake'],
            ],
        ]);
        $this->expectException(ObjectNotFoundException::class);
        $mgr->disk()->getMetadata('nope.txt');
    }

    public function testStaticFileStorageEntry(): void
    {
        $mgr = new FileStorageManager([
            'default_provider' => 'fake',
            'file_system_providers' => [
                'fake' => ['driver' => 'fake'],
            ],
        ]);
        FileStorage::setManager($mgr);
        FileStorage::disk()->putObject('s.txt', '1');
        $this->assertSame('1', FileStorage::disk()->getObject('s.txt'));
    }

    public function testInvalidPathThroughDisk(): void
    {
        $mgr = new FileStorageManager([
            'default_provider' => 'fake',
            'file_system_providers' => [
                'fake' => ['driver' => 'fake'],
            ],
        ]);
        $this->expectException(InvalidObjectPathException::class);
        $mgr->disk()->putObject('../escape.txt', 'x');
    }
}
