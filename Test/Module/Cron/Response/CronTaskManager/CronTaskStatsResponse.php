<?php

declare(strict_types=1);

namespace Test\Module\Cron\Response\CronTaskManager;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Http\BaseResponse;

class CronTaskStatsResponse extends BaseResponse
{
    #[ApiProperty(description: '任务 ID')]
    protected int $taskId;

    #[ApiProperty(description: '样本总数')]
    protected int $total;

    #[ApiProperty(description: '判定为成功的次数')]
    protected int $success;

    #[ApiProperty(description: '判定为失败的次数')]
    protected int $failed;

    #[ApiProperty(description: '判定为跳过的次数')]
    protected int $skipped;

    #[ApiProperty(description: '成功率（百分比）')]
    protected float $successRate;

    #[ApiProperty(description: '平均耗时（毫秒）')]
    protected float $avgDurationMs;

    #[ApiProperty(description: '参与耗时统计的样本数')]
    protected int $samples;

    public function __construct(
        int $taskId,
        int $total,
        int $success,
        int $failed,
        int $skipped,
        float $successRate,
        float $avgDurationMs,
        int $samples
    ) {
        $this->setTaskId($taskId);
        $this->setTotal($total);
        $this->setSuccess($success);
        $this->setFailed($failed);
        $this->setSkipped($skipped);
        $this->setSuccessRate($successRate);
        $this->setAvgDurationMs($avgDurationMs);
        $this->setSamples($samples);
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

    public function getSamples(): int
    {
        return $this->samples;
    }

    public function setSamples(int $samples): static
    {
        $this->samples = $samples;

        return $this;
    }

    public function getData(): array
    {
        return [
            'taskId' => $this->getTaskId(),
            'total' => $this->getTotal(),
            'success' => $this->getSuccess(),
            'failed' => $this->getFailed(),
            'skipped' => $this->getSkipped(),
            'successRate' => $this->getSuccessRate(),
            'avgDurationMs' => $this->getAvgDurationMs(),
            'samples' => $this->getSamples(),
        ];
    }
}
