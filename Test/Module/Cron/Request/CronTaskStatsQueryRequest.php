<?php

declare(strict_types=1);

namespace Test\Module\Cron\Request;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Annotation\StringToInt;
use Swoolefy\Annotation\Validation\ValidationRule;
use Swoolefy\Http\BaseRequest;

class CronTaskStatsQueryRequest extends BaseRequest
{
    #[ApiProperty(description: '任务 ID（cron 任务主键）')]
    #[ValidationRule(rule: 'required|int', message: 'task_id不能为空')]
    #[StringToInt]
    protected int $task_id = 0;

    public function getTaskId(): int
    {
        return $this->task_id;
    }

    public function setTaskId(int $task_id): self
    {
        $this->task_id = $task_id;

        return $this;
    }
}
