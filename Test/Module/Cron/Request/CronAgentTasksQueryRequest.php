<?php

declare(strict_types=1);

namespace Test\Module\Cron\Request;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Annotation\StringToInt;
use Swoolefy\Annotation\Validation\ValidationRule;
use Swoolefy\Http\BaseRequest;

class CronAgentTasksQueryRequest extends BaseRequest
{
    #[ApiProperty(description: '节点 ID')]
    #[ValidationRule(rule: 'required|int', message: 'node_id不能为空')]
    #[StringToInt]
    protected int $node_id = 0;

    #[ApiProperty(description: '执行类型：1 shell，2 http；省略则返回全部')]
    protected ?int $exec_type = null;

    public function getNodeId(): int
    {
        return $this->node_id;
    }

    public function setNodeId(int $node_id): self
    {
        $this->node_id = $node_id;

        return $this;
    }

    public function getExecType(): ?int
    {
        return $this->exec_type;
    }

    public function setExecType(?int $exec_type): self
    {
        $this->exec_type = $exec_type;

        return $this;
    }
}
