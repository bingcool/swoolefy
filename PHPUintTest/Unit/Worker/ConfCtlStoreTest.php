<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Worker;

use PHPUnit\Framework\TestCase;
use Swoolefy\Exception\WorkerException;
use Swoolefy\Worker\ConfCtlStore;

/**
 * confctl 原子读改写。
 * 覆盖独占锁读改写、损坏 JSON 不覆盖、并发更新不丢键、原子落盘后文件完整。
 */
final class ConfCtlStoreTest extends TestCase
{
    private string $tmpDir;

    private string $confFile;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/swoolefy_confctl_' . getmypid() . '_' . bin2hex(random_bytes(3));
        mkdir($this->tmpDir, 0777, true);
        $this->confFile = $this->tmpDir . '/confctl.json';
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->tmpDir)) {
            return;
        }
        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmpDir);
    }

    /**
     * 测 update 写入后文件为合法 JSON，且可读回相同内容。
     */
    public function testUpdateAtomicallyPersistsValidJson(): void
    {
        $store = new ConfCtlStore($this->confFile);
        $written = $store->update(function (array $data) {
            $data['procA'] = ['running' => 1, 'start_time' => 't1', 'stop_time' => ''];
            return $data;
        });

        $this->assertFileExists($this->confFile);
        $this->assertSame($written, $store->read());
        $raw = file_get_contents($this->confFile);
        $this->assertIsString($raw);
        $this->assertIsArray(json_decode($raw, true));
        $this->assertSame(1, json_decode($raw, true)['procA']['running']);
    }

    /**
     * 测损坏 JSON 抛错且原文件内容保持不变（不被空配置覆盖）。
     */
    public function testCorruptedJsonIsNotSilentlyOverwritten(): void
    {
        $broken = '{"procA":';
        file_put_contents($this->confFile, $broken);
        $store = new ConfCtlStore($this->confFile);

        try {
            $store->update(fn (array $data) => $data + ['x' => 1]);
            $this->fail('expected WorkerException for corrupted confctl');
        } catch (WorkerException $e) {
            $this->assertStringContainsString('corrupted', $e->getMessage());
        }

        $this->assertSame($broken, file_get_contents($this->confFile));
    }

    /**
     * 测 read 对损坏 JSON 同样报错且不改写文件。
     */
    public function testReadCorruptedJsonThrowsWithoutRewrite(): void
    {
        $broken = '{not-json';
        file_put_contents($this->confFile, $broken);
        $store = new ConfCtlStore($this->confFile);

        $this->expectException(WorkerException::class);
        try {
            $store->read();
        } finally {
            $this->assertSame($broken, file_get_contents($this->confFile));
        }
    }

    /**
     * 测多进程并发更新不同键不丢失（独占锁串行化读改写）。
     */
    public function testConcurrentUpdatesDoNotLoseDifferentKeys(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork required');
        }

        file_put_contents($this->confFile, json_encode([], JSON_UNESCAPED_UNICODE));
        $children = [];
        for ($i = 0; $i < 6; $i++) {
            $pid = pcntl_fork();
            $this->assertNotFalse($pid);
            if ($pid === 0) {
                $store = new ConfCtlStore($this->confFile);
                $key = 'k' . $i;
                try {
                    $store->update(function (array $data) use ($key) {
                        usleep(random_int(1000, 20000));
                        $data[$key] = ['running' => 1, 'v' => $key];
                        return $data;
                    });
                    exit(0);
                } catch (\Throwable $e) {
                    fwrite(STDERR, $e->getMessage() . PHP_EOL);
                    exit(1);
                }
            }
            $children[] = $pid;
        }

        foreach ($children as $pid) {
            $status = 0;
            pcntl_waitpid($pid, $status);
            $this->assertTrue(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, "child {$pid} failed");
        }

        $store = new ConfCtlStore($this->confFile);
        $data = $store->read();
        for ($i = 0; $i < 6; $i++) {
            $this->assertArrayHasKey('k' . $i, $data, 'concurrent update lost key k' . $i);
        }
    }

    /**
     * 测写入中断场景：临时文件失败时原文件保持完整。
     * 通过只读目录模拟原子写失败后原内容不变。
     */
    public function testFailedAtomicWriteKeepsOriginalFileIntact(): void
    {
        $original = json_encode(['keep' => ['running' => 1]], JSON_UNESCAPED_UNICODE);
        file_put_contents($this->confFile, $original);

        // 将目录改为只读，使临时文件创建/rename 失败
        chmod($this->tmpDir, 0555);
        $store = new ConfCtlStore($this->confFile);
        try {
            $store->update(function (array $data) {
                $data['keep']['running'] = 0;
                return $data;
            });
            $this->fail('expected write failure on read-only directory');
        } catch (WorkerException $e) {
            $this->assertNotEmpty($e->getMessage());
        } finally {
            chmod($this->tmpDir, 0777);
        }

        $this->assertSame($original, file_get_contents($this->confFile));
    }

    /**
     * 测 Linux 路径下 rename 可直接覆盖目标；Windows 分支逻辑通过源码契约覆盖。
     * 本用例验证同目录临时文件最终不残留、目标文件可重复 update。
     */
    public function testRepeatedUpdateLeavesNoTempFiles(): void
    {
        $store = new ConfCtlStore($this->confFile);
        $store->update(fn (array $d) => ['a' => ['running' => 1]] + $d);
        $store->update(fn (array $d) => ['b' => ['running' => 0]] + $d);

        $temps = glob($this->tmpDir . '/*.tmp') ?: [];
        $this->assertSame([], $temps);
        $data = $store->read();
        $this->assertArrayHasKey('a', $data);
        $this->assertArrayHasKey('b', $data);
    }

    /**
     * 测 defaultPath 优先使用 WORKER_CTL_CONF_FILE。
     */
    public function testDefaultPathPrefersWorkerCtlConfFileConstant(): void
    {
        if (!defined('WORKER_CTL_CONF_FILE')) {
            define('WORKER_CTL_CONF_FILE', $this->confFile);
        }
        $this->assertSame(WORKER_CTL_CONF_FILE, ConfCtlStore::defaultPath());
    }
}
