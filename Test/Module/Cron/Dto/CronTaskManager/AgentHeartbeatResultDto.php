<?php

declare(strict_types=1);

namespace Test\Module\Cron\Dto\CronTaskManager;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Core\Dto\AbstractDto;

/**
 * Agent 心跳响应结果 DTO。
 *
 * **职责**：回传心跳确认信息，含服务端当前时间供 Agent 对时参考。
 *
 * **生产者**：{@see \Test\Module\Cron\Service\CronTaskManagerService::agentHeartbeat} 校验
 * nodeId 后填充 serverTime。
 *
 * **消费者**：{@see \Test\Module\Cron\Controller\CronTaskManagerController::agentHeartbeat}
 * 读取字段并组装 {@see \Test\Module\Cron\Response\CronTaskManager\CronAgentHeartbeatResponse}。
 *
 * **关键字段语义**：
 * - nodeId：回显请求的节点 ID
 * - serverTime：服务端当前时间，格式 Y-m-d H:i:s
 */
class AgentHeartbeatResultDto extends AbstractDto
{
    #[ApiProperty(description: '回显的 Agent 节点 ID')]
    protected int $nodeId = 0;

    #[ApiProperty(description: '服务端当前时间（Y-m-d H:i:s）')]
    protected string $serverTime = '';

    /** 获取节点 ID */
    public function getNodeId(): int
    {
        return $this->nodeId;
    }

    /** 设置节点 ID */
    public function setNodeId(int $nodeId): static
    {
        $this->nodeId = $nodeId;

        return $this;
    }

    /** 获取服务端时间 */
    public function getServerTime(): string
    {
        return $this->serverTime;
    }

    /** 设置服务端时间 */
    public function setServerTime(string $serverTime): static
    {
        $this->serverTime = $serverTime;

        return $this;
    }
}
