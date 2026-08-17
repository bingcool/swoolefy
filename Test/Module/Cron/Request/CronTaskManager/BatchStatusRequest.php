<?php

declare(strict_types=1);

namespace Test\Module\Cron\Request\CronTaskManager;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Annotation\Validation\ValidationRule;
use Swoolefy\Http\BaseRequest;

/**
 * 批量启停：幂等赋值，不是 toggle。
 */
class BatchStatusRequest extends BaseRequest
{
    /**
     * @var list<int>
     */
    #[ApiProperty(description: '任务 ID 列表')]
    #[ValidationRule(rule: 'required|array', message: 'ids 不能为空')]
    protected array $ids = [];

    #[ApiProperty(description: '目标状态：0 禁用，1 启用')]
    #[ValidationRule(rule: 'required|int', message: 'status 不能为空')]
    protected int $status = 0;

    /**
     * @return list<int>
     */
    public function getIds(): array
    {
        $ids = [];
        foreach ($this->ids as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param list<int> $ids
     */
    public function setIds(array $ids): static
    {
        $this->ids = $ids;

        return $this;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function setStatus(int $status): static
    {
        $this->status = $status;

        return $this;
    }
}
