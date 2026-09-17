<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Worker;

use PHPUnit\Framework\TestCase;
use Swoolefy\Worker\Config\WorkerConfLoader;
use Swoolefy\Worker\Dto\PipeMsgDtoWorker;
use Swoolefy\Worker\MainManager;

/**
 * CLI FIFO 分发：STOP 走统一 shutdown；START 拒绝当前实例配置之外的进程。
 */
final class CliPipeDispatchTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        WorkerConfLoader::resetConfPath();
        $this->tmpDir = sys_get_temp_dir() . '/swoolefy_clipipe_' . getmypid() . '_' . bin2hex(random_bytes(3));
        mkdir($this->tmpDir, 0777, true);
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

        $dto = new PipeMsgDtoWorker();
        $dto->action = WORKER_CLI_STOP;
        $manager->getCliPipeServer()->dispatch($dto);

        $this->assertSame(['cli-pipe'], $manager->shutdownSources);
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
