<?php

declare(strict_types=1);

namespace Test\Module\Cron\Request\CronTaskManager;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Annotation\StringToInt;
use Swoolefy\Annotation\Validation\ValidationRule;
use Swoolefy\Http\BaseRequest;

/**
 * 单次执行详情查询。路由无 {id} 占位，用 query：id + execBatchId。
 */
class ExecutionDetailRequest extends BaseRequest
{
    #[ApiProperty(description: '任务 ID')]
    #[ValidationRule(rule: 'required|int', message: 'id 不能为空')]
    #[StringToInt]
    protected int $id = 0;

    #[ApiProperty(description: '执行批次 ID')]
    #[ValidationRule(rule: 'required|string', message: 'execBatchId 不能为空')]
    protected string $execBatchId = '';

    public function getId(): int
    {
        return $this->id;
    }

    public function setId(int $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function getExecBatchId(): string
    {
        return trim($this->execBatchId);
    }

    public function setExecBatchId(string $execBatchId): static
    {
        $this->execBatchId = $execBatchId;

        return $this;
    }
}
