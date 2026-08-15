<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Worker\Cron;

use PHPUintTest\TestCase;
use Swoolefy\Worker\Cron\ConfigDiff;
use Swoolefy\Worker\Cron\TaskDefinition;

/**
 * ConfigDiff 最小化 op 分类（文档 9/40.2）。
 *
 * 纯比较器单测：不创建 Timer / Registry。覆盖 ADD、fingerprint UPDATE、
 * ENABLE/DISABLE、desired 缺席 DELETE、身份未变 NOOP。
 *
 * @see \Swoolefy\Worker\Cron\ConfigDiff
 */
final class ConfigDiffTest extends TestCase
{
    /**
     * 空 runtime + desired → ADD；fingerprint/status 变化 → UPDATE/ENABLE/DISABLE；
     * desired 缺席 → DELETE；完全一致 → NOOP。
     */
    public function testAddUpdateDeleteEnableDisableNoop(): void
    {
        $diff = new ConfigDiff();
        $enabled = $this->def(1, '15', 1, 'old.sh');
        $disabled = $this->def(2, '15', 0, 'x.sh');

        $ops = $diff->diff([], ['id:1' => $enabled, 'id:2' => $disabled]);
        $this->assertSame(['ADD', 'ADD'], array_column($ops, 'op'));

        $runtime = ['id:1' => $enabled, 'id:2' => $disabled];
        $updated = $this->def(1, '20', 1, 'new.sh', '2026-01-02');
        $enabled2 = $this->def(2, '15', 1, 'x.sh');
        $ops = $diff->diff($runtime, ['id:1' => $updated, 'id:2' => $enabled2]);
        $this->assertContains(ConfigDiff::UPDATE, array_column($ops, 'op'));
        $this->assertContains(ConfigDiff::ENABLE, array_column($ops, 'op'));

        $ops = $diff->diff(
            ['id:1' => $updated, 'id:2' => $enabled2],
            ['id:1' => $this->def(1, '20', 0, 'new.sh', '2026-01-02')],
        );
        $this->assertContains(ConfigDiff::DISABLE, array_column($ops, 'op'));
        $this->assertContains(ConfigDiff::DELETE, array_column($ops, 'op'));

        $same = $this->def(1, '20', 0, 'new.sh', '2026-01-02');
        $ops = $diff->diff(['id:1' => $same], ['id:1' => $same]);
        $this->assertSame([ConfigDiff::NOOP], array_column($ops, 'op'));
    }

    /**
     * 按 id 生成稳定 jobId=id:{n} 的 TaskDefinition。
     */
    private function def(int $id, string $expression, int $status, string $command, string $updatedAt = '2026-01-01'): TaskDefinition
    {
        return TaskDefinition::fromArray([
            'id' => $id,
            'name' => 'job-' . $id,
            'expression' => $expression,
            'command' => $command,
            'exec_type' => 1,
            'status' => $status,
            'updated_at' => $updatedAt,
        ]);
    }
}
