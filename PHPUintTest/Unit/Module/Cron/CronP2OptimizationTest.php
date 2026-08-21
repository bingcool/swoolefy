<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Module\Cron;

use PHPUintTest\TestCase;
use Swoolefy\Worker\Cron\CronManager;
use Swoolefy\Worker\Cron\CronForkRunner;
use Test\Module\Cron\Service\CronTaskManagerService;

/**
 * 第二轮 P2：最小工程收敛（Runner 生命周期 / pid 等待 / 管理端批量查询与事务）。
 */
final class CronP2OptimizationTest extends TestCase
{
    public function testRunnerPidWaitUsesCoroutineFriendlySleep(): void
    {
        $src = (string) file_get_contents(
            (new \ReflectionClass(CronForkRunner::class))->getFileName()
        );

        $this->assertStringContainsString('while (microtime(true) < $deadline)', $src);
        $this->assertStringContainsString('System::sleep(0.1);', $src);
        $this->assertStringContainsString('usleep(100000);', $src);
        $this->assertStringNotContainsString('sleep(1);', $src);
    }

    public function testRunnerLifecycleHelpersExist(): void
    {
        $src = (string) file_get_contents(
            (new \ReflectionClass(CronForkRunner::class))->getFileName()
        );

        $this->assertStringContainsString('public static function removeRunnerIfIdle(string $runnerName): bool', $src);
        $this->assertStringContainsString('public static function removeAllRunners(bool $force = false): int', $src);
    }

    public function testCronManagerReleasesRunnerOnDeleteAndStop(): void
    {
        $src = (string) file_get_contents(
            (new \ReflectionClass(CronManager::class))->getFileName()
        );

        $this->assertStringContainsString('CronForkRunner::removeAllRunners(true);', $src);
        $this->assertStringContainsString('CronForkRunner::removeRunnerIfIdle(md5($definition->cronName));', $src);
    }

    public function testNodeListUsesGroupedTaskCountQuery(): void
    {
        $src = (string) file_get_contents(
            (new \ReflectionClass(CronTaskManagerService::class))->getFileName()
        );

        $this->assertStringContainsString("->whereIn('node_id', array_values(\$nodeIds))", $src);
        $this->assertStringContainsString("->group('node_id')", $src);
        $this->assertStringNotContainsString(
            "->where('node_id', (int)(\$row['id'] ?? 0))->count()",
            $src,
            'listNodes 不应逐节点 count（N+1）',
        );
    }

    public function testBatchSwitchStatusUsesTransactionAndBatchUpdate(): void
    {
        $src = (string) file_get_contents(
            (new \ReflectionClass(CronTaskManagerService::class))->getFileName()
        );

        $this->assertStringContainsString('$conn->beginTransaction();', $src);
        $this->assertStringContainsString('$conn->commit();', $src);
        $this->assertStringContainsString('$conn->rollBack();', $src);
        $this->assertStringContainsString("->whereIn('id', \$ids)", $src);
        $this->assertStringContainsString("->update([", $src);
        $this->assertStringContainsString("'status' => \$status", $src);
    }
}

