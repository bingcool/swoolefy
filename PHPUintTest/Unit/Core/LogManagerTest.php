<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Core;

use PHPUintTest\TestCase;
use Swoolefy\Core\Log\Formatter\LineFormatter;
use Swoolefy\Core\Log\LogManager;
use Swoolefy\Util\Log;

/**
 * LogManager 进程级日志（非协程单例）获取与复用。
 */
final class LogManagerTest extends TestCase
{
    /**
     * 验证：register 后 getLogger 返回同一 Log，可调用 setFormatter。
     */
    public function testGetLoggerReturnsProcessLevelLogInstance(): void
    {
        $type = 'unit_info_log_' . getmypid();
        $logRoot = sys_get_temp_dir() . '/swoolefy_log_manager_test_' . getmypid();
        if (!is_dir($logRoot)) {
            mkdir($logRoot, 0777, true);
        }
        $logFile = $logRoot . '/info.log';

        LogManager::getInstance()->registerLoggerByClosure(static function (string $name) use ($logFile) {
            $logger = new Log($name);
            $logger->setChannel('application');
            $logger->setLogFilePath($logFile);

            return $logger;
        }, $type);

        $logger = LogManager::getInstance()->getLogger($type);
        $this->assertInstanceOf(Log::class, $logger);
        $this->assertSame($logger, LogManager::getInstance()->getLogger($type));

        $formatter = new LineFormatter("%message%\n");
        $logger->setFormatter($formatter);
        $this->assertNotSame('', $logger->getLogFilePath());
        $this->assertStringContainsString('info.log', $logger->getLogFilePath());
    }

    /**
     * 验证：未注册通道 getLogger 返回 null（不会像 ComponentTrait::get 返回 false）。
     */
    public function testGetLoggerReturnsNullWhenUnregistered(): void
    {
        $this->assertNull(LogManager::getInstance()->getLogger('__not_registered_log__'));
    }
}
