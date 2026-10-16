<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Core;

use PHPUintTest\TestCase;
use Swoolefy\Constants;
use Swoolefy\Core\BaseServer;

/**
 * CLI 与 Worker 构造共用同一套 PHP / Swoole 下限。
 */
final class RuntimeVersionTest extends TestCase
{
    public function testCurrentRuntimePasses(): void
    {
        BaseServer::checkVersion();
        $this->assertSame('8.4.0', Constants::MIN_PHP_VERSION);
        $this->assertSame('6.1.0', Constants::MIN_SWOOLE_VERSION);
    }

    public function testPhpBelow84IsRejected(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('php version must be >= 8.4.0');
        BaseServer::checkVersion('8.3.9', '6.1.0');
    }

    public function testSwooleBelow61IsRejected(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Swoole version must >= 6.1.0');
        BaseServer::checkVersion(phpversion(), '6.0.9');
    }
}
