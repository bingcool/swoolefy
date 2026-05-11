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
    #[ApiProperty(description: '任务 ID（cron 任务主键）')]
    #[ValidationRule(rule: 'required|int', message: 'taskId 不能为空')]
    #[StringToInt]
    protected int $taskId = 0;

    public function getTaskId(): int
    {
        return $this->taskId;
    }

    public function setTaskId(int $taskId): static
    {
        $this->taskId = $taskId;

        return $this;
    }
}
