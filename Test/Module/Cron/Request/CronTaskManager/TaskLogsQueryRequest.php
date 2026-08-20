<?php

declare(strict_types=1);

namespace Test\Module\Cron\Request\CronTaskManager;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Annotation\StringToInt;
use Swoolefy\Annotation\Validation\ValidationRule;
use Swoolefy\Http\BasePageRequest;

/**
 * 任务执行日志分页查询。
 */
class TaskLogsQueryRequest extends BasePageRequest
{
    #[ApiProperty(description: '任务主键ID')]
    #[ValidationRule(rule: 'required|int', message: 'taskId 不能为空')]
    #[StringToInt]
    protected int $taskId = 0;

    #[ApiProperty(description: '执行批次 ID')]
    protected ?string $execBatchId = null;

    #[ApiProperty(description: '结果状态：pending/running/success/failed/skipped/timeout/cancelled')]
    protected ?string $status = null;

    #[ApiProperty(description: '开始时间（含）')]
    protected ?string $startTime = null;

    #[ApiProperty(description: '结束时间（含）')]
    protected ?string $endTime = null;

    public function getTaskId(): int
    {
        return $this->taskId;
    }

    public function setTaskId(int $taskId): static
    {
        $this->taskId = $taskId;

        return $this;
    }

    public function getExecBatchId(): ?string
    {
        return $this->execBatchId;
    }

    public function setExecBatchId(?string $execBatchId): static
    {
        $this->execBatchId = $execBatchId;

        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(?string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getStartTime(): ?string
    {
        return $this->startTime;
    }

    public function setStartTime(?string $startTime): static
    {
        $this->startTime = $startTime;

        return $this;
    }

    public function getEndTime(): ?string
    {
        return $this->endTime;
    }

    public function setEndTime(?string $endTime): static
    {
        $this->endTime = $endTime;

        return $this;
    }
}
