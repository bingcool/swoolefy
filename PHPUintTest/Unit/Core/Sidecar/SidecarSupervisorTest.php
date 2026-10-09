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
        $this->dir = sys_get_temp_dir() . '/sidecar-sup-' . uniqid('', true);
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
        $sock = $this->dir . '/' . $kind . '.sock';
        $this->installConf($sock);
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

    public function testRegularFileAndSymlinkAreNotDeleted(): void
    {
        $file = $this->dir . '/plain.sock';
        file_put_contents($file, 'not-a-socket');
        $this->installServer();
        $this->installConf($file);
        try {
            SidecarSupervisor::attach();
            $this->fail('expected sidecar exception');
        } catch (SidecarException) {
            $this->assertFileExists($file);
        }

        SidecarRegistry::reset();
        $target = $this->dir . '/target';
        file_put_contents($target, 'x');
        $link = $this->dir . '/link.sock';
        symlink($target, $link);
        $this->installConf($link);
        try {
            SidecarSupervisor::attach();
            $this->fail('expected sidecar exception');
        } catch (SidecarException) {
            $this->assertTrue(is_link($link));
        }
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

    private function installConf(string $socket): void
    {
        $prop = new ReflectionProperty(BaseServer::class, 'config');
        $prop->setAccessible(true);
        $prop->setValue(null, [
            'port' => 9501,
            'sidecar_runtime_dir' => $this->dir,
            'sidecars' => [[
                'sidecar_name' => 'rocketmq',
                'sidecar_enable' => true,
                'sidecar_exec_binary' => $this->binary,
                'sidecar_args' => [],
                'sidecar_socket_path' => $socket,
                'sidecar_plugins' => 'demo',
            ]],
            'rocketmq_consumers' => [],
        ]);
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
