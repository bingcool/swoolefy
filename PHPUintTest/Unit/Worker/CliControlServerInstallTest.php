<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Worker;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Swoolefy\Worker\MainManager;
use Swoolefy\Worker\Runtime\CliControlServer;

/**
 * install() 三条前置规则：陈旧 socket 可清、活实例占用拒绝、路径超长拒绝。
 */
final class CliControlServerInstallTest extends TestCase
{
    private string $baseDir;

    /** @var resource|null */
    private $boundServer;

    protected function setUp(): void
    {
        // sun_path 有长度上限，测试路径固定在 /tmp 且保持短
        $this->baseDir = '/tmp/sfc_' . getmypid() . '_' . bin2hex(random_bytes(3));
        mkdir($this->baseDir, 0750, true);
        $this->boundServer = null;
    }

    protected function tearDown(): void
    {
        if (is_resource($this->boundServer)) {
            @fclose($this->boundServer);
        }
        foreach (glob($this->baseDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->baseDir);
    }

    #[RunInSeparateProcess]
    public function testStaleSocketIsReplaced(): void
    {
        $socketPath = $this->baseDir . '/ctl.sock';
        $this->createSocketFile($socketPath);

        $pidFile = $this->baseDir . '/worker.pid';
        file_put_contents($pidFile, '999999');

        $server = new CliControlServer($this->newManager(), $socketPath, $pidFile);
        $this->assertTrue($server->install());
        $this->assertTrue($server->isListening());
        $this->assertSame('socket', filetype($socketPath));

        $this->drainReactorAndClose($server);
        $this->assertFalse($server->isListening());
    }

    #[RunInSeparateProcess]
    public function testOwnPidWithDeadSocketIsReplaced(): void
    {
        $socketPath = $this->baseDir . '/ctl.sock';
        $this->createSocketFile($socketPath);

        // 启动早期 AbstractProcess::__start() 已把本进程 pid 写进 pid 文件
        $pidFile = $this->baseDir . '/worker.pid';
        file_put_contents($pidFile, (string) getmypid());

        $server = new CliControlServer($this->newManager(), $socketPath, $pidFile);
        $this->assertTrue($server->install());
        $this->assertTrue($server->isListening());

        $this->drainReactorAndClose($server);
    }

    #[RunInSeparateProcess]
    public function testCloseRemovesOwnSocketEvenWhenPidFileIsStale(): void
    {
        $socketPath = $this->baseDir . '/ctl.sock';
        $pidFile = $this->baseDir . '/worker.pid';
        // 被 Swoole 重新拉起的实例可能读到上一个 MainManager 的 pid
        file_put_contents($pidFile, (string) (getmypid() + 1));

        $server = new CliControlServer($this->newManager(), $socketPath, $pidFile);
        $this->assertTrue($server->install());

        $this->drainReactorAndClose($server);
        $this->assertFileDoesNotExist($socketPath);
    }

    #[RunInSeparateProcess]
    public function testCloseKeepsSocketReplacedByNewInstance(): void
    {
        $socketPath = $this->baseDir . '/ctl.sock';
        $pidFile = $this->baseDir . '/worker.pid';
        file_put_contents($pidFile, (string) getmypid());

        $server = new CliControlServer($this->newManager(), $socketPath, $pidFile);
        $this->assertTrue($server->install());

        // 模拟新实例接管同一路径：旧实例 close() 不能删掉新文件
        unlink($socketPath);
        $this->boundServer = stream_socket_server('unix://' . $socketPath, $errno, $errstr);
        $this->assertIsResource($this->boundServer, $errstr);

        $this->drainReactorAndClose($server);
        $this->assertFileExists($socketPath);
    }

    public function testLiveInstanceSocketIsNotStolen(): void
    {
        $socketPath = $this->baseDir . '/ctl.sock';
        $this->boundServer = stream_socket_server('unix://' . $socketPath, $errno, $errstr);
        $this->assertIsResource($this->boundServer, $errstr);

        $pidFile = $this->baseDir . '/worker.pid';
        file_put_contents($pidFile, (string) getmypid());

        $server = new CliControlServer($this->newManager(), $socketPath, $pidFile);

        try {
            $server->install();
            $this->fail('活实例占用时 install() 必须拒绝');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('已被运行中的实例占用', $exception->getMessage());
        }

        $this->assertTrue(file_exists($socketPath));
    }

    public function testSocketPathTooLongIsRejected(): void
    {
        $socketPath = '/tmp/' . str_repeat('s', CliControlServer::MAX_SOCKET_PATH_BYTES);
        $server = new CliControlServer($this->newManager(), $socketPath, $this->baseDir . '/worker.pid');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/路径过长/');
        $server->install();
    }

    public function testDisabledControlServerDoesNotBind(): void
    {
        $manager = new class extends MainManager {
            public function __construct()
            {
                $this->enableCliControl(false);
            }

            protected function fmtWriteError($msg)
            {
            }
        };

        $server = new CliControlServer($manager, $this->baseDir . '/ctl.sock', $this->baseDir . '/worker.pid');
        $this->assertFalse($server->install());
        $this->assertFileDoesNotExist($this->baseDir . '/ctl.sock');
    }

    private function createSocketFile(string $socketPath): void
    {
        $errno = 0;
        $errstr = '';
        $this->boundServer = stream_socket_server('unix://' . $socketPath, $errno, $errstr);
        $this->assertIsResource($this->boundServer, $errstr);

        fclose($this->boundServer);
        $this->boundServer = null;
        $this->assertFileExists($socketPath);
    }

    private function drainReactorAndClose(CliControlServer $server): void
    {
        // install() 已初始化 Swoole reactor；空跑一拍再退出，
        // 否则进程 shutdown 时 Swoole 会调用已弃用的 Event::wait()。
        \Swoole\Timer::after(1, static function (): void {
            \Swoole\Event::exit();
        });
        \Swoole\Event::wait();

        $server->close();
    }

    private function newManager(): MainManager
    {
        return new class extends MainManager {
            public function __construct()
            {
            }

            protected function fmtWriteInfo($msg)
            {
            }

            protected function fmtWriteError($msg)
            {
            }
        };
    }
}
