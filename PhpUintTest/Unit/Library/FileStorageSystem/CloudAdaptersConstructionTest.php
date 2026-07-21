<?php

declare(strict_types=1);

namespace PhpUintTest\Unit\Library\FileStorageSystem;

use PhpUintTest\TestCase;
use PHPUnit\Framework\Attributes\Group;
use Swoolefy\Library\FileStorageSystem\Adapter\AliyunOss\AliyunOssAdapter;
use Swoolefy\Library\FileStorageSystem\Adapter\AwsS3\AwsS3Adapter;
use Swoolefy\Library\FileStorageSystem\Adapter\TencentCos\TencentCosAdapter;
use Swoolefy\Library\FileStorageSystem\Contracts\CloudClientAwareInterface;
use Swoolefy\Library\FileStorageSystem\Credential\StaticCredentialProvider;
use Swoolefy\Library\FileStorageSystem\Credential\StsCredentialProvider;
use Swoolefy\Library\FileStorageSystem\FileDisk;
use Swoolefy\Library\FileStorageSystem\FileStorageFactory;
use Swoolefy\Library\FileStorageSystem\FileStorageManager;
use Swoolefy\Library\FileStorageSystem\Support\ExpirationParser;
use Swoolefy\Library\FileStorageSystem\Exception\InvalidExpirationException;

/**
 * 云适配器构造 / Credential / Factory（不访问真实云）。
 */
#[Group('cloud')]
final class CloudAdaptersConstructionTest extends TestCase
{
    public function testAwsS3AdapterConstructsWithStaticCredentials(): void
    {
        $adapter = new AwsS3Adapter(
            bucket: 'test-bucket',
            credentials: new StaticCredentialProvider('AKIA_TEST', 'SECRET_TEST'),
            region: 'us-east-1',
        );
        $this->assertInstanceOf(\Aws\S3\S3Client::class, $adapter->getClient());
    }

    public function testAliyunOssAdapterConstructs(): void
    {
        $adapter = new AliyunOssAdapter(
            bucket: 'test-bucket',
            credentials: new StaticCredentialProvider('LTAI_TEST', 'SECRET_TEST'),
            region: 'cn-hangzhou',
        );
        $this->assertInstanceOf(\AlibabaCloud\Oss\V2\Client::class, $adapter->getClient());
    }

    public function testTencentCosAdapterConstructs(): void
    {
        $adapter = new TencentCosAdapter(
            bucket: 'test-bucket-1234567890',
            credentials: new StaticCredentialProvider('AKIDtest', 'secrettest'),
            region: 'ap-guangzhou',
            appId: '1234567890',
        );
        $this->assertInstanceOf(\Qcloud\Cos\Client::class, $adapter->getClient());
    }

    public function testFactoryBuildsCloudDisksSupportingClient(): void
    {
        $factory = new FileStorageFactory();
        $s3 = $factory->make('aws_s3', [
            'driver' => 'aws_s3',
            'bucket' => 'b',
            'key' => 'k',
            'secret' => 's',
            'region' => 'us-east-1',
        ]);
        $this->assertInstanceOf(FileDisk::class, $s3);
        $this->assertTrue($s3->supports(CloudClientAwareInterface::class));
        $this->assertSame('aws_s3', $s3->driver());

        $oss = $factory->make('aliyun_oss', [
            'driver' => 'aliyun_oss',
            'bucket' => 'b',
            'key' => 'k',
            'secret' => 's',
        ]);
        $this->assertTrue($oss->supports(CloudClientAwareInterface::class));

        $cos = $factory->make('tengxun_cos', [
            'driver' => 'tengxun_cos',
            'bucket' => 'b-123',
            'key' => 'k',
            'secret' => 's',
            'app_id' => '123',
        ]);
        $this->assertTrue($cos->supports(CloudClientAwareInterface::class));
    }

    public function testStsCredentialProviderRefreshesViaFetcher(): void
    {
        $calls = 0;
        $provider = new StsCredentialProvider(static function () use (&$calls): array {
            $calls++;

            return [
                'key' => 'sts-key',
                'secret' => 'sts-secret',
                'token' => 'tok',
                'expires_at' => time() + 3600,
            ];
        });
        $a = $provider->getCredentials();
        $b = $provider->getCredentials();
        $this->assertSame(1, $calls);
        $this->assertSame('sts-key', $a['key']);
        $this->assertSame($a, $b);
    }

    public function testExpirationParser(): void
    {
        $future = date('Y-m-d H:i:s', time() + 600);
        $seconds = ExpirationParser::toRelativeSeconds($future);
        $this->assertGreaterThan(500, $seconds);
        $this->assertLessThanOrEqual(600, $seconds);

        $this->expectException(InvalidExpirationException::class);
        ExpirationParser::toUnixTimestamp('not-a-date');
    }

    public function testManagerCanConfigureAllDriversWithoutNetwork(): void
    {
        $mgr = new FileStorageManager([
            'default_provider' => 'fake',
            'file_system_providers' => [
                'fake' => ['driver' => 'fake'],
                'aws_s3' => [
                    'driver' => 'aws_s3',
                    'bucket' => 'b',
                    'key' => 'k',
                    'secret' => 's',
                ],
                'aliyun_oss' => [
                    'driver' => 'aliyun_oss',
                    'bucket' => 'b',
                    'key' => 'k',
                    'secret' => 's',
                ],
                'tengxun_cos' => [
                    'driver' => 'tengxun_cos',
                    'bucket' => 'b-1',
                    'key' => 'k',
                    'secret' => 's',
                ],
            ],
        ]);
        $this->assertSame('fake', $mgr->disk()->driver());
        $this->assertSame('aws_s3', $mgr->disk('aws_s3')->driver());
        $this->assertSame('aliyun_oss', $mgr->disk('aliyun_oss')->driver());
        $this->assertSame('tengxun_cos', $mgr->disk('tengxun_cos')->driver());
    }
}
