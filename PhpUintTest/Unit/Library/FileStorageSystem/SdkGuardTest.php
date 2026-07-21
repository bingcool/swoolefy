<?php

declare(strict_types=1);

namespace PhpUintTest\Unit\Library\FileStorageSystem;

use PhpUintTest\TestCase;
use Swoolefy\Library\FileStorageSystem\Exception\MissingSdkException;
use Swoolefy\Library\FileStorageSystem\Support\SdkGuard;

/**
 * SDK 缺失检测：提示 composer require。
 */
final class SdkGuardTest extends TestCase
{
    public function testMissingComposerPackageMessage(): void
    {
        $e = MissingSdkException::missingComposerPackage(
            'aws/aws-sdk-php',
            'aws_s3',
            'Aws\\S3\\S3Client'
        );
        $this->assertStringContainsString('composer require aws/aws-sdk-php', $e->getMessage());
        $this->assertStringContainsString('aws_s3', $e->getMessage());
        $this->assertStringContainsString('Aws\\S3\\S3Client', $e->getMessage());
    }

    public function testRequireClassThrowsWhenMissing(): void
    {
        $this->expectException(MissingSdkException::class);
        $this->expectExceptionMessage('composer require example/missing-sdk');
        SdkGuard::requireClass(
            'Swoolefy\\Library\\FileStorageSystem\\DefinitelyMissingSdkClassXYZ',
            'example/missing-sdk',
            'demo'
        );
    }

    public function testRequireClassPassesWhenPresent(): void
    {
        SdkGuard::requireClass(\stdClass::class, 'php', 'demo');
        $this->assertTrue(true);
    }
}
