<?php

declare(strict_types=1);

namespace Test\Module\Cron\Dto\CronTaskManager;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Core\Dto\AbstractDto;

/**
 * Agent 心跳上报入参 DTO。
 *
 * **职责**：标识发起心跳的 Agent 节点，供服务端校验并回传当前时间。
 *
 * **生产者**：{@see \Test\Module\Cron\Controller\CronTaskManagerController::agentHeartbeat}
 * 通过 {@see static::of} 从 Request 的 nodeId 构造。
 *
 * **消费者**：{@see \Test\Module\Cron\Service\CronTaskManagerService::agentHeartbeat} 校验
 * nodeId 后 upsert 心跳并返回 {@see AgentHeartbeatResultDto}。
 *
 * **关键字段语义**：nodeId 为 cron_agent_node 主键，必须 >0。
 */
class AgentHeartbeatDto extends AbstractDto
{
    #[ApiProperty(description: '上报心跳的 Agent 节点 ID')]
    protected int $nodeId = 0;

    /**
     * 快捷工厂：由节点 ID 构造心跳入参。
     *
     * @param int $nodeId cron_agent_node 主键
     */
    public static function of(int $nodeId): self
    {
        $dto = new self();
        $dto->nodeId = $nodeId;

        return $dto;
    }

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
}
