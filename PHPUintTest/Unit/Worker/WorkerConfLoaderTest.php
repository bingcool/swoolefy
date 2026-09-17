<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Worker;

use PHPUnit\Framework\TestCase;
use Swoolefy\Exception\WorkerException;
use Swoolefy\Worker\Config\WorkerConfLoader;
use Swoolefy\Worker\ConfCtlStore;

/**
 * WorkerConfLoader：扁平 conf、分组、去重、confctl running=0 过滤。
 */
final class WorkerConfLoaderTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        WorkerConfLoader::resetConfPath();
        $this->tmpDir = sys_get_temp_dir() . '/swoolefy_loader_' . getmypid() . '_' . bin2hex(random_bytes(3));
        mkdir($this->tmpDir, 0777, true);
        putenv('group');
    }

    protected function tearDown(): void
    {
        WorkerConfLoader::resetConfPath();
        putenv('group');
        if (!is_dir($this->tmpDir)) {
            return;
        }
        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmpDir);
    }

    public function testIsGroupedWorkerConfDetectsFlatAndGrouped(): void
    {
        $this->assertFalse(WorkerConfLoader::isGroupedWorkerConf([]));
        $this->assertFalse(WorkerConfLoader::isGroupedWorkerConf([
            ['process_name' => 'p1', 'handler' => 'H1'],
        ]));
        $this->assertTrue(WorkerConfLoader::isGroupedWorkerConf([
            'group_1' => [
                ['process_name' => 'p1', 'handler' => 'H1'],
            ],
        ]));
    }

    public function testResolveGroupedWorkerConfDefaultMergesAllGroups(): void
    {
        $grouped = [
            'group_1' => [
                ['process_name' => 'p1', 'handler' => 'H1'],
            ],
            'group_2' => [
                ['process_name' => 'p2', 'handler' => 'H2'],
            ],
        ];

        putenv('group');
        $all = WorkerConfLoader::resolveGroupedWorkerConf($grouped);
        $this->assertSame(['p1', 'p2'], array_column($all, 'process_name'));

        putenv('group=group_2');
        $only2 = WorkerConfLoader::resolveGroupedWorkerConf($grouped);
        $this->assertSame(['p2'], array_column($only2, 'process_name'));
    }

    public function testFindDuplicateProcessNameThrows(): void
    {
        $conf = [
            ['process_name' => 'dup', 'handler' => 'H1'],
            ['process_name' => 'dup', 'handler' => 'H2'],
        ];
        $this->expectException(WorkerException::class);
        $this->expectExceptionMessage('dup');
        WorkerConfLoader::findDuplicateProcessName($conf);
    }

    public function testIncludeFlatConfAndDuplicateDetection(): void
    {
        $file = $this->tmpDir . '/worker_conf.php';
        file_put_contents($file, "<?php\nreturn [\n    ['process_name' => 'order-sync', 'handler' => 'H1'],\n    ['process_name' => 'mail', 'handler' => 'H2'],\n];\n");

        WorkerConfLoader::useConfFile($file);
        $loaded = WorkerConfLoader::includeWorkerConf();
        $this->assertSame(['order-sync', 'mail'], array_column($loaded, 'process_name'));
    }

    public function testDefaultLoadWorkerConfSkipsRunningZeroAndDropsStaleCtl(): void
    {
        $confFile = $this->tmpDir . '/worker_conf.php';
        file_put_contents($confFile, "<?php\nreturn [\n    ['process_name' => 'keep', 'handler' => 'H1'],\n    ['process_name' => 'stopped', 'handler' => 'H2'],\n];\n");

        $ctlFile = $this->tmpDir . '/confctl.json';
        $store = new ConfCtlStore($ctlFile);
        $store->update(static function () {
            return [
                'keep' => ['running' => 1, 'start_time' => 't1', 'stop_time' => ''],
                'stopped' => ['running' => 0, 'start_time' => 't2', 'stop_time' => 't3'],
                'stale' => ['running' => 1, 'start_time' => 't4', 'stop_time' => ''],
            ];
        });

        WorkerConfLoader::resetConfPath();
        $loaded = WorkerConfLoader::defaultLoadWorkerConf($confFile, $store);
        $this->assertSame(['keep'], array_column($loaded, 'process_name'));

        $ctl = $store->read();
        $this->assertArrayHasKey('keep', $ctl);
        $this->assertArrayHasKey('stopped', $ctl);
        $this->assertArrayNotHasKey('stale', $ctl);
    }

    public function testUnknownGroupThrows(): void
    {
        $this->expectException(WorkerException::class);
        $this->expectExceptionMessage('missing');
        putenv('group=missing');
        WorkerConfLoader::resolveGroupedWorkerConf([
            'group_1' => [
                ['process_name' => 'p1', 'handler' => 'H1'],
            ],
        ]);
    }
}
