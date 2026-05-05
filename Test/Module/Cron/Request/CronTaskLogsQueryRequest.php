<?php

declare(strict_types=1);

namespace Test\Module\Cron\Request;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Annotation\StringToInt;
use Swoolefy\Annotation\Validation\ValidationRule;
use Swoolefy\Http\BaseRequest;

class CronTaskLogsQueryRequest extends BaseRequest
{
    #[ApiProperty(description: '任务 ID（cron 任务主键）')]
    #[ValidationRule(rule: 'required|int', message: 'task_id不能为空')]
    #[StringToInt]
    protected int $task_id = 0;

    #[ApiProperty(description: '页码')]
    protected ?int $page = null;

    #[ApiProperty(description: '每页条数')]
    protected ?int $page_size = null;

    public function getTaskId(): int
    {
        return $this->task_id;
    }

    public function setTaskId(int $task_id): self
    {
        $this->task_id = $task_id;

        return $this;
    }

    public function getPage(): int
    {
        return max(1, (int)($this->page ?? 1));
    }

    public function setPage(?int $page): self
    {
        $this->page = $page;

        return $this;
    }

    public function getPageSize(): int
    {
        return max(1, min(100, (int)($this->page_size ?? 20)));
    }

    public function setPageSize(?int $page_size): self
    {
        $this->page_size = $page_size;

        return $this;
    }
}
