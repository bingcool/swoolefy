<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Worker;

use PHPUnit\Framework\TestCase;
use Swoolefy\Worker\Config\WorkerConfLoader;
use Swoolefy\Worker\Dto\ControlRequest;
use Swoolefy\Worker\MainManager;
use Swoolefy\Worker\Runtime\ReplySink;

/**
 * 控制面分发：STOP 走统一 shutdown；START 拒绝当前实例配置之外的进程。
 */
final class CliControlDispatchTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        WorkerConfLoader::resetConfPath();
        $this->tmpDir = '/tmp/swoolefy_cli_ctl_' . getmypid() . '_' . bin2hex(random_bytes(3));
        mkdir($this->tmpDir, 0750, true);
        putenv('group');
    }

    protected function tearDown(): void
    {
        WorkerConfLoader::resetConfPath();
        putenv('group');
        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmpDir);
    }

    public function testStopDispatchesUnifiedShutdown(): void
    {
        $manager = new class extends MainManager {
            public array $shutdownSources = [];

            public function __construct()
            {
            }

            public function shutdownMainManager(string $source): void
            {
                $this->shutdownSources[] = $source;
            }

            protected function fmtWriteInfo($msg)
            {
            }

            protected function fmtWriteError($msg)
            {
            }
        };

        $sink = new ReplySink();
        $manager->getCliControlServer()->dispatch(new ControlRequest(WORKER_CLI_STOP), $sink);

        $this->assertSame(['cli-control'], $manager->shutdownSources);
        $this->assertTrue($sink->hasResponded());
        $this->assertTrue($sink->isClosed());
        $this->assertCount(1, $sink->sentFrames());

        $frame = $sink->sentFrames()[0];
        $length = unpack('Nlen', substr($frame, 0, 4))['len'];
        $payload = json_decode(substr($frame, 4, $length), true);
        $this->assertTrue($payload['ok']);
        $this->assertGreaterThan(0, (float) $payload['data']);
    }

    public function testStartUnknownProcessDoesNotRegisterList(): void
    {
        $confFile = $this->tmpDir . '/worker_conf.php';
        file_put_contents($confFile, "<?php\nreturn [\n    'group_1' => [\n        ['process_name' => 'in-group', 'handler' => 'H1'],\n    ],\n];\n");
        WorkerConfLoader::useConfFile($confFile);
        putenv('group=group_1');

        $manager = new class extends MainManager {
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

        $handler = $manager->getCommandHandler();
        $this->assertSame([], $handler->parseLoadConf('other-group-process'));
        $this->assertNotEmpty($handler->parseLoadConf('in-group'));
        $this->assertFalse($manager->getRegistry()->hasList('other-group-process'));
    }

    public function testSendStartUnknownProcessRepliesFailure(): void
    {
        $confFile = $this->tmpDir . '/worker_conf.php';
        file_put_contents($confFile, "<?php\nreturn [\n    'group_1' => [\n        ['process_name' => 'in-group', 'handler' => 'H1'],\n    ],\n];\n");
        WorkerConfLoader::useConfFile($confFile);
        putenv('group=group_1');

        $manager = new class extends MainManager {
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

        $sink = new ReplySink();
        $request = new ControlRequest(WORKER_CLI_SEND_MSG, 'not-exist', WORKER_CLI_START, '', 'n1');
        $manager->getCliControlServer()->dispatch($request, $sink);

        $this->assertTrue($sink->isClosed());
        $frame = $sink->sentFrames()[0];
        $json = substr($frame, 4);

        $this->assertStringContainsString('找不到进程名', $json);
        $this->assertStringContainsString('"ok":false', $json);
        $this->assertStringContainsString('"nonce":"n1"', $json);
    }

    public function testParseLoadConfHonorsCurrentGroup(): void
    {
        $confFile = $this->tmpDir . '/worker_conf.php';
        file_put_contents($confFile, "<?php\nreturn [\n    'group_1' => [\n        ['process_name' => 'p1', 'handler' => 'H1'],\n    ],\n    'group_2' => [\n        ['process_name' => 'p2', 'handler' => 'H2'],\n    ],\n];\n");
        WorkerConfLoader::resetConfPath();
        WorkerConfLoader::useConfFile($confFile);

        putenv('group=group_1');
        $manager = new class extends MainManager {
            public function __construct()
            {
            }
        };

        $this->assertSame('p1', $manager->getCommandHandler()->parseLoadConf('p1')['process_name'] ?? null);
        $this->assertSame([], $manager->getCommandHandler()->parseLoadConf('p2'));
    }
}
