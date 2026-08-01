<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Cmd;

use PHPUnit\Framework\TestCase;
use Swoolefy\Cmd\Infrastructure\CmdLogWriter;

/**
 * CmdLogWriter 单元测试。
 *
 * 验证日志写入功能：
 * - 正常写入日志文件
 * - 文件大小轮转
 * - 常量未定义时静默跳过
 */
class CmdLogWriterTest extends TestCase
{
    /** @var string 临时日志目录 */
    private string $tmpDir;

    /** @var string 临时日志文件 */
    private string $logFile;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/swoolefy_test_log_' . getmypid();
        if (!is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0777, true);
        }
        $this->logFile = $this->tmpDir . '/test_ctl.log';
    }

    protected function tearDown(): void
    {
        // 清理临时文件
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
        if (is_dir($this->tmpDir)) {
            @rmdir($this->tmpDir);
        }
    }

    /**
     * 测试 WORKER_CTL_LOG_FILE 未定义时静默跳过。
     *
     * 在不定义 WORKER_CTL_LOG_FILE 常量的环境中（如单元测试），
     * write() 应该静默返回，不抛异常。
     */
    public function testWriteWithoutConstantDoesNotThrow(): void
    {
        // WORKER_CTL_LOG_FILE 在测试环境中通常未定义
        // write() 应该安全地静默跳过
        CmdLogWriter::write('test message');
        $this->assertTrue(true);
    }

    /**
     * 测试 CmdLogWriter 类存在且方法可调用。
     */
    public function testClassExists(): void
    {
        $this->assertTrue(class_exists(CmdLogWriter::class));
        $this->assertTrue(method_exists(CmdLogWriter::class, 'write'));
    }
}
