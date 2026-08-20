<?php

declare(strict_types=1);

namespace Test\Module\Cron\Request\CronTaskManager;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Annotation\StringToInt;
use Swoolefy\Annotation\Validation\ValidationRule;
use Swoolefy\Http\BaseRequest;

class CronTaskStatsQueryRequest extends BaseRequest
{
    #[ApiProperty(description: '任务 ID（cron 任务主键）')]
    #[ValidationRule(rule: 'required|int', message: 'taskId 不能为空')]
    #[StringToInt]
    protected int $taskId = 0;

    #[ApiProperty(description: '开始时间（含 created_at >= start），空表示不限制')]
    protected ?string $start = null;

    #[ApiProperty(description: '结束时间（不含 created_at < end），空表示不限制')]
    protected ?string $end = null;

    public function getTaskId(): int
    {
        return $this->taskId;
    }

    public function setTaskId(int $taskId): static
    {
        $this->taskId = $taskId;

        return $this;
    }

    public function getStart(): ?string
    {
        return $this->start;
    }

    public function setStart(?string $start): static
    {
        $this->start = $start;

        return $this;
    }

    public function getEnd(): ?string
    {
        return $this->end;
    }

    public function setEnd(?string $end): static
    {
        $this->end = $end;

        return $this;
    }
}
