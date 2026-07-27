<?php

declare(strict_types=1);

namespace PhpUintTest\Unit\Cmd;

use PHPUnit\Framework\TestCase;
use Swoolefy\Cmd\Infrastructure\PidFileManager;

/**
 * PidFileManager 单元测试。
 *
 * 验证 PID 文件的读取、删除、死亡清理等操作。
 * 使用临时文件模拟 PID 文件，避免依赖真实服务进程。
 */
class PidFileManagerTest extends TestCase
{
    /** @var string 临时目录，用于创建测试 PID 文件 */
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/swoolefy_test_pid_' . getmypid();
        if (!is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        // 清理临时文件
        if (is_dir($this->tmpDir)) {
            $files = glob($this->tmpDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            @rmdir($this->tmpDir);
        }
    }

    /**
     * 测试 read()：正常 PID 文件读取。
     */
    public function testReadValidPid(): void
    {
        $pidFile = $this->tmpDir . '/test.pid';
        file_put_contents($pidFile, '12345');

        $this->assertSame(12345, PidFileManager::read($pidFile));
    }

    /**
     * 测试 read()：文件不存在返回 0。
     */
    public function testReadNonExistentFile(): void
    {
        $this->assertSame(0, PidFileManager::read('/tmp/nonexistent_12345.pid'));
    }

    /**
     * 测试 read()：无效内容返回 0。
     */
    public function testReadInvalidContent(): void
    {
        $pidFile = $this->tmpDir . '/invalid.pid';
        file_put_contents($pidFile, 'not_a_number');

        $this->assertSame(0, PidFileManager::read($pidFile));
    }

    /**
     * 测试 read()：空文件返回 0。
     */
    public function testReadEmptyFile(): void
    {
        $pidFile = $this->tmpDir . '/empty.pid';
        file_put_contents($pidFile, '');

        $this->assertSame(0, PidFileManager::read($pidFile));
    }

    /**
     * 测试 remove()：删除存在的 PID 文件。
     */
    public function testRemoveExistingFile(): void
    {
        $pidFile = $this->tmpDir . '/remove_test.pid';
        file_put_contents($pidFile, '12345');

        $this->assertFileExists($pidFile);
        PidFileManager::remove($pidFile);
        $this->assertFileDoesNotExist($pidFile);
    }

    /**
     * 测试 remove()：删除不存在的文件不报错。
     */
    public function testRemoveNonExistentFile(): void
    {
        // 不应抛异常
        PidFileManager::remove('/tmp/nonexistent_12345.pid');
        $this->assertTrue(true); // 到达此处即通过
    }

    /**
     * 测试 removeIfDead()：进程已死亡时删除 PID 文件。
     */
    public function testRemoveIfDeadWithDeadProcess(): void
    {
        $pidFile = $this->tmpDir . '/dead.pid';
        // 使用一个极不可能存在的 PID
        file_put_contents($pidFile, '999999999');

        PidFileManager::removeIfDead($pidFile);
        $this->assertFileDoesNotExist($pidFile);
    }

    /**
     * 测试 removeIfDead()：进程存活时不删除 PID 文件。
     */
    public function testRemoveIfDeadWithAliveProcess(): void
    {
        $pidFile = $this->tmpDir . '/alive.pid';
        // 使用当前进程 PID（一定存活）
        file_put_contents($pidFile, (string) getmypid());

        PidFileManager::removeIfDead($pidFile);
        $this->assertFileExists($pidFile);
    }

    /**
     * 测试 removeIfDead()：文件不存在时不报错。
     */
    public function testRemoveIfDeadNonExistent(): void
    {
        PidFileManager::removeIfDead('/tmp/nonexistent_12345.pid');
        $this->assertTrue(true);
    }

    /**
     * 测试 isRunning()：进程存活时返回 true。
     */
    public function testIsRunningWithAliveProcess(): void
    {
        $pidFile = $this->tmpDir . '/running.pid';
        file_put_contents($pidFile, (string) getmypid());

        $this->assertTrue(PidFileManager::isRunning($pidFile));
    }

    /**
     * 测试 isRunning()：文件不存在返回 false。
     */
    public function testIsRunningNoFile(): void
    {
        $this->assertFalse(PidFileManager::isRunning('/tmp/nonexistent_12345.pid'));
    }

    /**
     * 测试 isRunning()：进程已死亡返回 false。
     */
    public function testIsRunningDeadProcess(): void
    {
        $pidFile = $this->tmpDir . '/dead_running.pid';
        file_put_contents($pidFile, '999999999');

        $this->assertFalse(PidFileManager::isRunning($pidFile));
    }
}
