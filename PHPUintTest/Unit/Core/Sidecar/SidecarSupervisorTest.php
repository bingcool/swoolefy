<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Core\Sidecar;

use PHPUintTest\TestCase;
use ReflectionProperty;
use Swoole\Process;
use Swoole\Server;
use Swoolefy\Core\BaseServer;
use Swoolefy\Core\Sidecar\SidecarException;
use Swoolefy\Core\Sidecar\SidecarRegistry;
use Swoolefy\Core\Sidecar\SidecarSpec;
use Swoolefy\Core\Sidecar\SidecarSupervisor;
use Swoolefy\Core\Sidecar\SocketEndpoint;

final class SidecarSupervisorTest extends TestCase
{
    private string $dir = '';

    private string $binary = '';

    /** @var array<string, mixed> */
    private array $savedConf = [];

    private mixed $savedServer = null;

    protected function setUp(): void
    {
        parent::setUp();
        // macOS 的 socket 路径上限 103 字节，sys_get_temp_dir() 太长，用 /tmp。
        $this->dir = '/tmp/sc-sup-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700, true);
        $this->binary = $this->dir . '/bin';
        file_put_contents($this->binary, "#!/bin/sh\nexit 0\n");
        chmod($this->binary, 0755);
        $config = new ReflectionProperty(BaseServer::class, 'config');
        $config->setAccessible(true);
        $this->savedConf = $config->getValue();
        $server = new ReflectionProperty(BaseServer::class, 'server');
        $server->setAccessible(true);
        $this->savedServer = $server->getValue();
        SidecarRegistry::reset();
        if (!defined('APP_NAME')) {
            define('APP_NAME', 'SidecarSup');
        }
    }

    protected function tearDown(): void
    {
        SidecarRegistry::reset();
        $config = new ReflectionProperty(BaseServer::class, 'config');
        $config->setAccessible(true);
        $config->setValue(null, $this->savedConf);
        $server = new ReflectionProperty(BaseServer::class, 'server');
        $server->setAccessible(true);
        $server->setValue(null, $this->savedServer);
        $this->removeTree($this->dir);
        parent::tearDown();
    }

    public function testAddProcessZeroIsSuccessAndFalseFails(): void
    {
        $server = new class extends Server {
            public array $added = [];
            public int|false $result = 0;

            public function __construct()
            {
            }

            public function addProcess(Process $process): int|false
            {
                $this->added[] = $process;

                return $this->result;
            }
        };
        $spec = $this->spec($this->dir . '/zero.sock');
        $this->assertSame(0, SidecarSupervisor::spawn($server, $spec));
        $server->result = false;
        $this->expectException(SidecarException::class);
        SidecarSupervisor::spawn($server, $spec);
    }

    /**
     * @dataProvider serviceKinds
     */
    public function testAttachRegistersForEveryServiceKind(string $kind): void
    {
        $server = $this->installServer();
        $sockDir = $this->dir . '/' . $kind;
        mkdir($sockDir, 0700, true);
        $this->installConf($sockDir);
        SidecarSupervisor::attach();
        $this->assertCount(1, $server->added, $kind);
        $this->assertStringNotContainsString('isWorkerService', (string) file_get_contents(
            dirname(__DIR__, 4) . '/src/Core/Sidecar/SidecarSupervisor.php'
        ));
    }

    /**
     * @return list<array{0: string}>
     */
    public static function serviceKinds(): array
    {
        return [['http'], ['daemon'], ['cron'], ['script']];
    }

    public function testAttachClearsOnlyCurrentServiceDirectory(): void
    {
        $dir = $this->dir . '/plain-dir';
        $service = SocketEndpoint::serviceName();
        $other = $service === 'cron' ? 'cli' : 'cron';
        mkdir($dir . '/' . $service, 0700, true);
        mkdir($dir . '/' . $other, 0700, true);
        $this->staleSocket($dir . '/' . $service . '/old.sock');
        $this->staleSocket($dir . '/' . $other . '/old.sock');
        $this->installServer();
        $this->installConf($dir, true);
        SidecarSupervisor::attach();

        $this->assertFileDoesNotExist($dir . '/' . $service . '/old.sock');
        $this->assertDirectoryExists($dir . '/' . $service);
        $this->assertFileExists($dir . '/' . $other . '/old.sock');

        SidecarRegistry::reset();
        $dir = $this->dir . '/link-dir';
        mkdir($dir, 0700, true);
        $target = $this->dir . '/target';
        mkdir($target, 0700, true);
        file_put_contents($target . '/keep.txt', 'x');
        $link = $dir . '/' . $service;
        symlink($target, $link);
        $this->installConf($dir, true);
        try {
            SidecarSupervisor::attach();
            $this->fail('symlink service directory must be refused');
        } catch (SidecarException) {
            $this->assertFileExists($target . '/keep.txt');
            $this->assertTrue(is_link($link));
        }
    }

    public function testAttachKeepsStaleSocketsUnlessCleanOnStart(): void
    {
        $dir = $this->dir . '/keep';
        $service = SocketEndpoint::serviceName();
        mkdir($dir . '/' . $service, 0700, true);
        $this->staleSocket($dir . '/' . $service . '/old.sock');
        $this->installServer();
        $this->installConf($dir);
        SidecarSupervisor::attach();

        $this->assertFileExists($dir . '/' . $service . '/old.sock');
        $this->assertDirectoryExists($dir . '/' . $service);
    }

    public function testUnlinkOwnSocketsRemovesRegisteredFilesAndIgnoresFailure(): void
    {
        $dir = $this->dir . '/own';
        mkdir($dir, 0700, true);
        $own = $dir . '/own.sock';
        $other = $dir . '/other.sock';
        $this->staleSocket($own);
        $this->staleSocket($other);
        SidecarRegistry::add($this->spec($own));
        SidecarSupervisor::unlinkOwnSockets();
        $this->assertFileDoesNotExist($own);
        $this->assertFileExists($other);

        SidecarSupervisor::unlinkOwnSockets();
        $this->assertFileExists($other);

        $locked = $dir . '/locked';
        mkdir($locked, 0700, true);
        $stuck = $locked . '/stuck.sock';
        $this->staleSocket($stuck);
        chmod($locked, 0555);
        SidecarRegistry::reset();
        SidecarRegistry::add($this->spec($stuck));
        set_error_handler(static function (int $severity, string $message): bool {
            throw new \ErrorException($message, 0, $severity);
        });
        try {
            SidecarSupervisor::unlinkOwnSockets();
            $this->assertFileExists($stuck);
        } finally {
            restore_error_handler();
            chmod($locked, 0700);
        }
    }

    public function testClearUnusedSocketsKeepsLiveOnesAndTheDirectory(): void
    {
        $runtime = $this->dir . '/app';
        $parent = $runtime . '/sidecars_sock';
        $cli = $parent . '/cli';
        $cron = $parent . '/cron';
        mkdir($cli, 0700, true);
        mkdir($cron, 0700, true);
        $this->staleSocket($cli . '/stale.sock');
        $live = stream_socket_server('unix://' . $cli . '/live.sock', $errno, $errstr);
        $this->assertIsResource($live);
        file_put_contents($cli . '/note.txt', 'x');
        $this->staleSocket($cron . '/old.sock');

        try {
            SocketEndpoint::clearUnusedSockets($cli);
        } finally {
            fclose($live);
        }

        $this->assertFileDoesNotExist($cli . '/stale.sock');
        $this->assertFileExists($cli . '/live.sock');
        $this->assertFileExists($cli . '/note.txt');
        $this->assertDirectoryExists($cli);
        $this->assertFileExists($cron . '/old.sock');

        $script = $parent . '/script';
        mkdir($script, 0700, true);
        $this->staleSocket($script . '/stale.sock');
        $running = stream_socket_server('unix://' . $script . '/running.sock', $errno, $errstr);
        $this->assertIsResource($running);
        try {
            SocketEndpoint::clearUnusedSockets($script);
        } finally {
            fclose($running);
        }
        $this->assertFileDoesNotExist($script . '/stale.sock');
        $this->assertFileExists($script . '/running.sock');
        $this->assertDirectoryExists($script);

        $target = $this->dir . '/elsewhere';
        mkdir($target, 0700, true);
        file_put_contents($target . '/keep.txt', 'x');
        symlink($target, $cli . '-link');
        try {
            SocketEndpoint::clearUnusedSockets($cli . '-link');
            $this->fail('symlink directory must be refused');
        } catch (SidecarException) {
            $this->assertFileExists($target . '/keep.txt');
            $this->assertTrue(is_link($cli . '-link'));
        }

        try {
            SocketEndpoint::clearUnusedSockets($parent);
            $this->fail('parent directory must be refused');
        } catch (SidecarException) {
            $this->assertDirectoryExists($parent);
        }
    }

    private function staleSocket(string $path): void
    {
        $socket = socket_create(AF_UNIX, SOCK_STREAM, 0);
        $this->assertNotFalse($socket);
        $this->assertTrue(@socket_bind($socket, $path));
        socket_close($socket);
    }

    public function testMissingSocketIgnoresWarningFromErrorHandler(): void
    {
        $path = $this->dir . '/missing.sock';
        set_error_handler(static function (int $severity, string $message): bool {
            throw new \ErrorException($message, 0, $severity);
        });
        try {
            SocketEndpoint::prepare($path);
        } finally {
            restore_error_handler();
        }
        $this->assertFileDoesNotExist($path);
        $this->assertSame(0700, fileperms($this->dir) & 0777);
    }

    public function testPrepareUnlinksStaleSocketAndRefusesLiveListener(): void
    {
        $stale = $this->dir . '/stale.sock';
        $listener = socket_create(AF_UNIX, SOCK_STREAM, 0);
        $this->assertNotFalse($listener);
        $this->assertTrue(@socket_bind($listener, $stale));
        socket_close($listener);
        SocketEndpoint::prepare($stale);
        $this->assertFileDoesNotExist($stale);

        $live = $this->dir . '/live.sock';
        $fp = stream_socket_server('unix://' . $live, $errno, $errstr);
        $this->assertIsResource($fp);
        try {
            SocketEndpoint::prepare($live);
            $this->fail('live socket must not be removed');
        } catch (SidecarException) {
            $this->assertFileExists($live);
        } finally {
            fclose($fp);
        }
    }

    private function installServer(): Server
    {
        $server = new class extends Server {
            public array $added = [];

            public function __construct()
            {
            }

            public function addProcess(Process $process): int|false
            {
                $this->added[] = $process;

                return 1;
            }
        };
        $prop = new ReflectionProperty(BaseServer::class, 'server');
        $prop->setAccessible(true);
        $prop->setValue(null, $server);

        return $server;
    }

    private function installConf(string $socket, bool $cleanOnStart = false): void
    {
        $prop = new ReflectionProperty(BaseServer::class, 'config');
        $prop->setAccessible(true);
        $conf = [
            'port' => 9501,
            'sidecar_runtime_dir' => $this->dir,
            'sidecars' => [[
                'sidecar_name' => 'rocketmq',
                'sidecar_enable' => true,
                'sidecar_exec_binary' => $this->binary,
                'sidecar_args' => [],
                'sidecar_socket_path' => $socket,
                'sidecar_plugins' => 'rocketmq',
                'sidecar_rocketmq_proxy_endpoint' => '127.0.0.1:8081',
                'sidecar_rocketmq_allowed_topics' => '*',
            ]],
        ];
        if ($cleanOnStart) {
            $conf['sidecar_clean_sockets_on_start'] = true;
        }
        $prop->setValue(null, $conf);
    }

    private function spec(string $socket): SidecarSpec
    {
        return new SidecarSpec(
            name: 'rocketmq',
            binary: $this->binary,
            args: [],
            socketPath: $socket,
            maxFrameBytes: 65536,
            defaultTimeoutMs: 1000,
            maxTimeoutMs: 5000,
            maxInflight: 4,
            shutdownGraceMs: 1000,
            plugins: 'demo',
            environment: ['SIDECAR_NAME' => 'rocketmq'],
        );
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_link($path) || is_file($path)) {
                @unlink($path);
                continue;
            }
            if (is_dir($path)) {
                $this->removeTree($path);
            }
        }
        @rmdir($dir);
    }
}
