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
    #[ApiProperty(description: '任务主键ID，不传则返回全部任务日志')]
    #[ValidationRule(rule: 'nullable|int', message: 'taskId 必须是整数')]
    #[StringToInt]
    protected ?int $taskId = null;

    #[ApiProperty(description: '执行批次 ID')]
    protected ?string $execBatchId = null;

    #[ApiProperty(description: '结果状态：pending/running/success/failed/skipped/timeout/cancelled')]
    protected ?string $status = null;

    #[ApiProperty(description: '执行类型：1=shell, 2=http')]
    #[ValidationRule(rule: 'nullable|int', message: 'execType 必须是整数')]
    #[StringToInt]
    protected ?int $execType = null;

    #[ApiProperty(description: '任务名称（模糊搜索）')]
    protected ?string $taskName = null;

    #[ApiProperty(description: '开始时间（含）')]
    protected ?string $startTime = null;

    #[ApiProperty(description: '结束时间（含）')]
    protected ?string $endTime = null;

    public function getTaskId(): ?int
    {
        return $this->taskId;
    }

    public function setTaskId(?int $taskId): static
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

    public function getExecType(): ?int
    {
        return $this->execType;
    }

    public function setExecType(?int $execType): static
    {
        $this->execType = $execType;

        return $this;
    }

    public function getTaskName(): ?string
    {
        return $this->taskName;
    }

    public function setTaskName(?string $taskName): static
    {
        $this->taskName = $taskName;

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
