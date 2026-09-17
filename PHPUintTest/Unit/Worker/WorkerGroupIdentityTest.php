<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Worker;

use PHPUnit\Framework\TestCase;
use Swoolefy\Worker\Helper;
use Swoolefy\Worker\MainManager;

/**
 * Daemon --group 解析与实例隔离后缀。
 */
final class WorkerGroupIdentityTest extends TestCase
{
    /**
     * 测逗号分隔、去空、去重。
     */
    public function testParseGroupNamesNormalizesList(): void
    {
        $this->assertSame([], Helper::parseGroupNames(null));
        $this->assertSame([], Helper::parseGroupNames(''));
        $this->assertSame(['group_1'], Helper::parseGroupNames('group_1'));
        $this->assertSame(['group_1', 'group_2'], Helper::parseGroupNames('group_1, group_2,,group_1'));
    }

    /**
     * 测 argv 读取 --group=，顺序无关且可用于目录名。
     */
    public function testDaemonGroupInstanceSuffixIsStableAndSafe(): void
    {
        $this->assertSame('', Helper::daemonGroupInstanceSuffix(['daemon.php', 'start', 'Test']));
        $this->assertSame('group_1', Helper::daemonGroupInstanceSuffix([
            'daemon.php', 'start', 'Test', '--group=group_1',
        ]));
        $this->assertSame('group_1+group_2', Helper::daemonGroupInstanceSuffix([
            'daemon.php', 'start', 'Test', '--group=group_2,group_1',
        ]));
        $this->assertSame('group_1+group_2', Helper::daemonGroupInstanceSuffix([
            'daemon.php', 'start', 'Test', '--group=group_1,group_2',
        ]));
    }

    /**
     * 测未指定 --group 时合并全部组；指定后只返回这些组。
     */
    public function testResolveGroupedWorkerConfFiltersByGroup(): void
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
        $all = MainManager::resolveGroupedWorkerConf($grouped);
        $this->assertSame(['p1', 'p2'], array_column($all, 'process_name'));

        putenv('group=group_2');
        $only2 = MainManager::resolveGroupedWorkerConf($grouped);
        $this->assertSame(['p2'], array_column($only2, 'process_name'));

        putenv('group=group_1,group_2');
        $both = MainManager::resolveGroupedWorkerConf($grouped);
        $this->assertSame(['p1', 'p2'], array_column($both, 'process_name'));

        putenv('group');
    }

    /**
     * 测重启参数带回 --group / --only。
     */
    public function testWorkerRestartOptionsCarryGroup(): void
    {
        putenv('ENV_CLI_PARAMS=' . json_encode(['group' => 'group_1', 'only' => 'p1', 'force' => '1']));
        $this->assertSame([
            '--group' => 'group_1',
            '--only' => 'p1',
        ], Helper::workerRestartInputOptions());
        $this->assertSame('--group=' . escapeshellarg('group_1') . ' --only=' . escapeshellarg('p1'), Helper::workerRestartCliSuffix());
        putenv('ENV_CLI_PARAMS');
    }
}
