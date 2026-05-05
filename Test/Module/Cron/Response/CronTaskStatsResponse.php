<?php

declare(strict_types=1);

namespace Test\Module\Cron\Response;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Http\BaseResponse;

class CronTaskStatsResponse extends BaseResponse
{
    #[ApiProperty(description: '任务 ID')]
    protected int $task_id;

    #[ApiProperty(description: '样本总数')]
    protected int $total;

    #[ApiProperty(description: '判定为成功的次数')]
    protected int $success;

    #[ApiProperty(description: '判定为失败的次数')]
    protected int $failed;

    #[ApiProperty(description: '判定为跳过的次数')]
    protected int $skipped;

    #[ApiProperty(description: '成功率（百分比）')]
    protected float $success_rate;

    #[ApiProperty(description: '平均耗时（毫秒）')]
    protected float $avg_duration_ms;

    #[ApiProperty(description: '参与耗时统计的样本数')]
    protected int $samples;

    public function __construct(
        int $task_id,
        int $total,
        int $success,
        int $failed,
        int $skipped,
        float $success_rate,
        float $avg_duration_ms,
        int $samples
    ) {
        $this->setTaskId($task_id);
        $this->setTotal($total);
        $this->setSuccess($success);
        $this->setFailed($failed);
        $this->setSkipped($skipped);
        $this->setSuccessRate($success_rate);
        $this->setAvgDurationMs($avg_duration_ms);
        $this->setSamples($samples);
    }

    public function getTaskId(): int
    {
        return $this->task_id;
    }

    public function setTaskId(int $task_id): self
    {
        $this->task_id = $task_id;

        return $this;
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    public function setTotal(int $total): self
    {
        $this->total = $total;

        return $this;
    }

    public function getSuccess(): int
    {
        return $this->success;
    }

    public function setSuccess(int $success): self
    {
        $this->success = $success;

        return $this;
    }

    public function getFailed(): int
    {
        return $this->failed;
    }

    public function setFailed(int $failed): self
    {
        $this->failed = $failed;

        return $this;
    }

    public function getSkipped(): int
    {
        return $this->skipped;
    }

    public function setSkipped(int $skipped): self
    {
        $this->skipped = $skipped;

        return $this;
    }

    public function getSuccessRate(): float
    {
        return $this->success_rate;
    }

    public function setSuccessRate(float $success_rate): self
    {
        $this->success_rate = $success_rate;

        return $this;
    }

    public function getAvgDurationMs(): float
    {
        return $this->avg_duration_ms;
    }

    public function setAvgDurationMs(float $avg_duration_ms): self
    {
        $this->avg_duration_ms = $avg_duration_ms;

        return $this;
    }

    public function getSamples(): int
    {
        return $this->samples;
    }

    public function setSamples(int $samples): self
    {
        $this->samples = $samples;

        return $this;
    }

    public function getData(): array
    {
        return [
            'task_id' => $this->getTaskId(),
            'total' => $this->getTotal(),
            'success' => $this->getSuccess(),
            'failed' => $this->getFailed(),
            'skipped' => $this->getSkipped(),
            'success_rate' => $this->getSuccessRate(),
            'avg_duration_ms' => $this->getAvgDurationMs(),
            'samples' => $this->getSamples(),
        ];
    }
}
