<?php

declare(strict_types=1);

namespace Test\Module\Cron\Request;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Annotation\StringToInt;
use Swoolefy\Annotation\Validation\ValidationRule;
use Swoolefy\Http\BaseRequest;

class CronAgentHeartbeatRequest extends BaseRequest
{
    #[ApiProperty(description: '节点 ID')]
    #[ValidationRule(rule: 'required|int', message: 'node_id不能为空')]
    #[StringToInt]
    protected int $node_id = 0;

    public function getNodeId(): int
    {
        return $this->node_id;
    }

    public function setNodeId(int $node_id): self
    {
        $this->node_id = $node_id;

        return $this;
    }
}
