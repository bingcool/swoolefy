<?php

declare(strict_types=1);

namespace Test\Module\Cron\Request\CronTaskManager;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Annotation\StringToInt;
use Swoolefy\Annotation\Validation\ValidationRule;
use Swoolefy\Http\BaseRequest;

class CronAgentHeartbeatRequest extends BaseRequest
{
    #[ApiProperty(description: '节点 ID')]
    #[ValidationRule(rule: 'required|int', message: 'nodeId 不能为空')]
    #[StringToInt]
    protected int $nodeId = 0;

    public function getNodeId(): int
    {
        return $this->nodeId;
    }

    public function setNodeId(int $nodeId): static
    {
        $this->nodeId = $nodeId;

        return $this;
    }
}
