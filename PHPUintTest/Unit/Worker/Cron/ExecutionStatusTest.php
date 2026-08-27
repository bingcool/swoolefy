<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Worker\Cron;

use PHPUintTest\TestCase;
use Swoolefy\Worker\Cron\ExecutionResult;
use Swoolefy\Worker\Cron\ExecutionStatus;

/**
 * cron_task_log.status 聚合与历史 message 迁移映射。
 *
 * taskStats 只消费本类的 GROUP BY 结果，禁止再用 message 猜状态。
 */
final class ExecutionStatusTest extends TestCase
{
    public function testEmptyCountsHasCompleteZeroStructure(): void
    {
        $stats = ExecutionStatus::emptyCounts();
        foreach (['total', 'register', 'running', 'success', 'failed', 'skipped', 'timeout', 'cancelled', 'unregister', 'finished', 'attempted', 'samples'] as $key) {
            $this->assertSame(0, $stats[$key], $key);
        }
        $this->assertSame(0.0, $stats['successRate']);
        $this->assertSame(0.0, $stats['avgDurationMs']);
        $this->assertSame(0.0, $stats['maxDurationMs']);
    }

    public function testAggregateCountsMatchesDocFixture(): void
    {
        $stats = ExecutionStatus::aggregateCounts([
            ['status' => ExecutionStatus::SUCCESS, 'total' => 10],
            ['status' => ExecutionStatus::FAILED, 'total' => 2],
            ['status' => ExecutionStatus::SKIPPED, 'total' => 3],
            ['status' => ExecutionStatus::TIMEOUT, 'total' => 1],
        ]);

        $this->assertSame(16, $stats['total']);
        $this->assertSame(10, $stats['success']);
        $this->assertSame(2, $stats['failed']);
        $this->assertSame(3, $stats['skipped']);
        $this->assertSame(1, $stats['timeout']);
        $this->assertSame(0, $stats['cancelled']);
        $this->assertSame(16, $stats['finished']);
        $this->assertSame(13, $stats['attempted'], '成功率分母不含 SKIPPED');
        $this->assertSame(76.92, $stats['successRate']);
    }

    public function testMessageTextIsNotUsedByAggregator(): void
    {
        $stats = ExecutionStatus::aggregateCounts([
            ['status' => ExecutionStatus::SUCCESS, 'total' => 1, 'message' => '执行失败？'],
        ]);
        $this->assertSame(1, $stats['success']);
        $this->assertSame(0, $stats['failed']);
    }

    public function testUnknownStatusDoesNotMasqueradeAsRegister(): void
    {
        $stats = ExecutionStatus::aggregateCounts([
            ['status' => 99, 'total' => 4],
        ]);
        $this->assertSame(4, $stats['total']);
        $this->assertSame(0, $stats['register']);
    }

    public function testRegisterDoesNotCountAsFinishedOrAttempted(): void
    {
        $stats = ExecutionStatus::aggregateCounts([
            ['status' => ExecutionStatus::REGISTER, 'total' => 3],
            ['status' => ExecutionStatus::SUCCESS, 'total' => 1],
        ]);
        $this->assertSame(4, $stats['total']);
        $this->assertSame(3, $stats['register']);
        $this->assertSame(1, $stats['finished']);
        $this->assertSame(1, $stats['attempted']);
    }

    public function testUnregisterDoesNotCountAsFinishedOrAttempted(): void
    {
        $stats = ExecutionStatus::aggregateCounts([
            ['status' => ExecutionStatus::UNREGISTER, 'total' => 4],
            ['status' => ExecutionStatus::SUCCESS, 'total' => 1],
        ]);
        $this->assertSame(5, $stats['total']);
        $this->assertSame(4, $stats['unregister']);
        $this->assertSame(1, $stats['finished']);
        $this->assertSame(1, $stats['attempted']);
        $this->assertSame(0, $stats['failed']);
    }

    public function testFromResultAndNameRoundTrip(): void
    {
        $this->assertSame(ExecutionStatus::SUCCESS, ExecutionStatus::fromResult(ExecutionResult::success()));
        $this->assertSame(ExecutionStatus::FAILED, ExecutionStatus::fromResult(ExecutionResult::failed('x')));
        $this->assertSame(ExecutionStatus::SKIPPED, ExecutionStatus::fromResult(ExecutionResult::skipped('x')));
        $this->assertSame(ExecutionStatus::TIMEOUT, ExecutionStatus::fromResult(ExecutionResult::timeout('x')));
        $this->assertSame(ExecutionStatus::CANCELLED, ExecutionStatus::fromResult(ExecutionResult::cancelled('x')));
        $this->assertSame('timeout', ExecutionStatus::name(ExecutionStatus::TIMEOUT));
        $this->assertSame(ExecutionStatus::FAILED, ExecutionStatus::fromName('failed'));
        $this->assertSame(ExecutionStatus::TIMEOUT, ExecutionStatus::fromName('5'));
        $this->assertSame(ExecutionStatus::REGISTER, ExecutionStatus::fromName('register'));
        $this->assertSame(ExecutionStatus::REGISTER, ExecutionStatus::fromName('pending'));
        $this->assertSame(ExecutionStatus::REGISTER, ExecutionStatus::fromName('0'));
        $this->assertSame('register', ExecutionStatus::name(ExecutionStatus::REGISTER));
        $this->assertSame(ExecutionStatus::UNREGISTER, ExecutionStatus::fromName('unregister'));
        $this->assertSame(ExecutionStatus::UNREGISTER, ExecutionStatus::fromName('7'));
        $this->assertSame('unregister', ExecutionStatus::name(ExecutionStatus::UNREGISTER));
        $this->assertNull(ExecutionStatus::fromName('unknown'));
    }

    public function testInferFromLegacyMessageIsConservative(): void
    {
        $this->assertSame(ExecutionStatus::SUCCESS, ExecutionStatus::inferFromLegacyMessage('【job】SUCCESS ok'));
        $this->assertSame(ExecutionStatus::FAILED, ExecutionStatus::inferFromLegacyMessage('【job】FAILED boom'));
        $this->assertSame(ExecutionStatus::SKIPPED, ExecutionStatus::inferFromLegacyMessage('【job】SKIP 时间窗跳过'));
        $this->assertSame(ExecutionStatus::SUCCESS, ExecutionStatus::inferFromLegacyMessage('success'));
        $this->assertNull(ExecutionStatus::inferFromLegacyMessage('执行失败？'), '不能把无法确认的文案写成 FAILED/REGISTER');
        $this->assertNull(ExecutionStatus::inferFromLegacyMessage('【job】开始执行 cron_expression=15'));
        $this->assertNull(ExecutionStatus::inferFromLegacyMessage('【job】ADD 注册定时任务'));
    }

    public function testTriggerType(): void
    {
        $this->assertSame(ExecutionStatus::TRIGGER_RUN_ONCE, ExecutionStatus::triggerType('runOnceNow'));
        $this->assertSame(ExecutionStatus::TRIGGER_SCHEDULER, ExecutionStatus::triggerType('trigger'));
    }

    public function testWithDurationDoesNotParseMessage(): void
    {
        $stats = ExecutionStatus::withDuration(ExecutionStatus::emptyCounts(), 12.5, 40.0, 3);
        $this->assertSame(12.5, $stats['avgDurationMs']);
        $this->assertSame(40.0, $stats['maxDurationMs']);
        $this->assertSame(3, $stats['samples']);
    }
}
