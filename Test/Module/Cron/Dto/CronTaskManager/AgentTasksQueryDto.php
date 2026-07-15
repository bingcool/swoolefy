<?php

declare(strict_types=1);

namespace Test\Module\Cron\Dto\CronTaskManager;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Core\Dto\AbstractDto;

/**
 * Agent 拉取待执行定时任务查询入参 DTO。
 *
 * **职责**：指定 Agent 节点及可选的执行类型过滤，供 Worker 侧拉取启用中的任务元数据。
 *
 * **生产者**：{@see \Test\Module\Cron\Controller\CronTaskManagerController::agentTasks} 从
 * {@see \Test\Module\Cron\Request\CronTaskManager\CronAgentTasksQueryRequest} 映射字段。
 *
 * **消费者**：{@see \Test\Module\Cron\Service\CronTaskManagerService::agentTasks} 委托
 * {@see \Test\Module\Cron\Service\CronTaskService::fetchCronTask} 查询并返回
 * {@see AgentTasksResultDto}。
 *
 * **关键字段语义**：
 * - nodeId：Agent 绑定的节点 ID，必须 >0
 * - execType：1=仅 shell，2=仅 http；null 或非法值时同时返回 shell + http 两类任务
 */
class AgentTasksQueryDto extends AbstractDto
{
    #[ApiProperty(description: 'Agent 节点 ID（cron_agent_node 主键）')]
    protected int $nodeId = 0;

    #[ApiProperty(description: '执行类型过滤：1=shell，2=http；null 表示不过滤')]
    protected ?int $execType = null;

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

    /** 获取执行类型过滤条件 */
    public function getExecType(): ?int
    {
        return $this->execType;
    }

    /** 设置执行类型过滤条件 */
    public function setExecType(?int $execType): static
    {
        $this->execType = $execType;

        return $this;
    }
}
