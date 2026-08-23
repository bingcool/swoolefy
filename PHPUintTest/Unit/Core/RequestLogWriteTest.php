<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Core;

use PHPUintTest\TestCase;
use Swoolefy\Util\Log;

/**
 * Request/hourly log writes must survive missing files and fresh log directories.
 */
final class RequestLogWriteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 3) . '/src/Support/Tests/SwoolefyTestBootstrap.php';
        set_error_handler(static function (int $errorNo, string $errorMessage, string $errorFile, int $errorLine): bool {
            if ((\PHP_VERSION_ID >= 80400 && \in_array($errorNo, [E_NOTICE, E_DEPRECATED], true))
                || (\PHP_VERSION_ID < 80400 && \in_array($errorNo, [E_NOTICE, E_DEPRECATED, E_STRICT], true))
            ) {
                return false;
            }
            throw new \ErrorException($errorMessage, 0, $errorNo, $errorFile, $errorLine);
        });
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        parent::tearDown();
    }

    public function testHourlyLogCreatesNestedDirectoriesAndSurvivesMissingFileInodeCheck(): void
    {
        $logRoot = sys_get_temp_dir() . '/swoolefy_request_log_test_' . getmypid() . '_' . uniqid('', true);
        $this->assertDirectoryDoesNotExist($logRoot);

        $logger = new Log('request_log');
        $logger->setChannel('application');
        $logger->enableHourly();
        $logger->setHandlerInodeCheckInterval(1);
        $logger->setLogFilePath($logRoot . '/request/request.log');

        $logger->addInfo('first write');
        $logFile = $logger->getLogFilePath();
        $this->assertFileExists($logFile);
        $this->assertStringContainsString('/request/', $logFile);
        $this->assertStringContainsString('request-', $logFile);

        sleep(2);
        $logger->addInfo('second write');
        $this->assertFileExists($logFile);

        unlink($logFile);
        sleep(2);
        $logger->addInfo('after file removed');
        $this->assertFileExists($logFile);

        $this->removeDirectory($logRoot);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isDir()) {
                @rmdir($fileInfo->getPathname());
            } else {
                @unlink($fileInfo->getPathname());
            }
        }
        @rmdir($dir);
    }
}
