<?php

declare(strict_types=1);

namespace Test\Module\Cron\Dto\CronTaskManager;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Core\Dto\AbstractDto;
use Swoolefy\Worker\Cron\ExecutionStatus;

/**
 * 任务执行统计结果 DTO。
 *
 * **职责**：承载对单条任务执行记录的结构化聚合指标，供管理端看板展示。
 *
 * **生产者**：{@see \Test\Module\Cron\Service\CronTaskManagerService::taskStats}
 * 通过 SQL `GROUP BY status` 聚合，再由 {@see static::fromAggregated} 组装。
 *
 * **消费者**：{@see \Test\Module\Cron\Controller\CronTaskManagerController::taskStats} 组装
 * {@see \Test\Module\Cron\Response\CronTaskManager\CronTaskStatsResponse}。
 *
 * **关键字段语义**：
 * - total：时间范围内 Execution 行总数（含 register/running）
 * - successRate：success / attempted × 100；attempted 不含 SKIPPED（未真正执行）
 * - avgDurationMs / maxDurationMs：仅 SUCCESS 行的 duration_ms，不解析 message
 */
class CronTaskStatsResultDto extends AbstractDto
{
    #[ApiProperty(description: '被统计的任务 ID')]
    protected int $taskId = 0;

    #[ApiProperty(description: '执行记录总条数')]
    protected int $total = 0;

    #[ApiProperty(description: 'register（注册定时任务）次数')]
    protected int $register = 0;

    #[ApiProperty(description: 'running 次数')]
    protected int $running = 0;

    #[ApiProperty(description: '成功次数')]
    protected int $success = 0;

    #[ApiProperty(description: '失败次数')]
    protected int $failed = 0;

    #[ApiProperty(description: '跳过次数')]
    protected int $skipped = 0;

    #[ApiProperty(description: '超时次数')]
    protected int $timeout = 0;

    #[ApiProperty(description: '取消次数')]
    protected int $cancelled = 0;

    #[ApiProperty(description: '已结束次数（success+failed+skipped+timeout+cancelled）')]
    protected int $finished = 0;

    #[ApiProperty(description: '成功率分母（success+failed+timeout+cancelled，不含 skipped）')]
    protected int $attempted = 0;

    #[ApiProperty(description: '成功率（百分比，0–100）；分母为 attempted')]
    protected float $successRate = 0.0;

    #[ApiProperty(description: 'SUCCESS 行平均执行耗时（毫秒）')]
    protected float $avgDurationMs = 0.0;

    #[ApiProperty(description: 'SUCCESS 行最大执行耗时（毫秒）')]
    protected float $maxDurationMs = 0.0;

    #[ApiProperty(description: '参与耗时统计的 SUCCESS 行数')]
    protected int $samples = 0;

    /**
     * 由 GROUP BY 聚合结果构造。空数据返回完整零值结构。
     *
     * @param array<string, mixed> $stats {@see ExecutionStatus::aggregateCounts()}
     */
    public static function fromAggregated(int $taskId, array $stats): self
    {
        $empty = ExecutionStatus::emptyCounts();
        $stats = array_merge($empty, $stats);
        $dto = new self();
        $dto->taskId = $taskId;
        $dto->total = (int) $stats['total'];
        $dto->register = (int) $stats['register'];
        $dto->running = (int) $stats['running'];
        $dto->success = (int) $stats['success'];
        $dto->failed = (int) $stats['failed'];
        $dto->skipped = (int) $stats['skipped'];
        $dto->timeout = (int) $stats['timeout'];
        $dto->cancelled = (int) $stats['cancelled'];
        $dto->finished = (int) $stats['finished'];
        $dto->attempted = (int) $stats['attempted'];
        $dto->successRate = (float) $stats['successRate'];
        $dto->avgDurationMs = (float) $stats['avgDurationMs'];
        $dto->maxDurationMs = (float) $stats['maxDurationMs'];
        $dto->samples = (int) $stats['samples'];

        return $dto;
    }

    public function getTaskId(): int
    {
        return $this->taskId;
    }

    public function setTaskId(int $taskId): static
    {
        $this->taskId = $taskId;

        return $this;
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    public function setTotal(int $total): static
    {
        $this->total = $total;

        return $this;
    }

    public function getRegister(): int
    {
        return $this->register;
    }

    public function setRegister(int $register): static
    {
        $this->register = $register;

        return $this;
    }

    public function getRunning(): int
    {
        return $this->running;
    }

    public function setRunning(int $running): static
    {
        $this->running = $running;

        return $this;
    }

    public function getSuccess(): int
    {
        return $this->success;
    }

    public function setSuccess(int $success): static
    {
        $this->success = $success;

        return $this;
    }

    public function getFailed(): int
    {
        return $this->failed;
    }

    public function setFailed(int $failed): static
    {
        $this->failed = $failed;

        return $this;
    }

    public function getSkipped(): int
    {
        return $this->skipped;
    }

    public function setSkipped(int $skipped): static
    {
        $this->skipped = $skipped;

        return $this;
    }

    public function getTimeout(): int
    {
        return $this->timeout;
    }

    public function setTimeout(int $timeout): static
    {
        $this->timeout = $timeout;

        return $this;
    }

    public function getCancelled(): int
    {
        return $this->cancelled;
    }

    public function setCancelled(int $cancelled): static
    {
        $this->cancelled = $cancelled;

        return $this;
    }

    public function getFinished(): int
    {
        return $this->finished;
    }

    public function setFinished(int $finished): static
    {
        $this->finished = $finished;

        return $this;
    }

    public function getAttempted(): int
    {
        return $this->attempted;
    }

    public function setAttempted(int $attempted): static
    {
        $this->attempted = $attempted;

        return $this;
    }

    public function getSuccessRate(): float
    {
        return $this->successRate;
    }

    public function setSuccessRate(float $successRate): static
    {
        $this->successRate = $successRate;

        return $this;
    }

    public function getAvgDurationMs(): float
    {
        return $this->avgDurationMs;
    }

    public function setAvgDurationMs(float $avgDurationMs): static
    {
        $this->avgDurationMs = $avgDurationMs;

        return $this;
    }

    public function getMaxDurationMs(): float
    {
        return $this->maxDurationMs;
    }

    public function setMaxDurationMs(float $maxDurationMs): static
    {
        $this->maxDurationMs = $maxDurationMs;

        return $this;
    }

    public function getSamples(): int
    {
        return $this->samples;
    }

    public function setSamples(int $samples): static
    {
        $this->samples = $samples;

        return $this;
    }
}
