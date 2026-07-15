<?php

declare(strict_types=1);

namespace Test\Module\Cron\Dto\CronTaskManager;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Core\Dto\AbstractDto;

/**
 * 创建 Cron Agent 节点入参 DTO。
 *
 * **职责**：承载新建 Agent 执行节点所需的名称、IP 与备注信息。
 *
 * **生产者**：{@see \Test\Module\Cron\Controller\CronTaskManagerController::createNode} 从
 * {@see \Test\Module\Cron\Request\CronTaskManager\CronNodeCreateRequest} 映射字段。
 *
 * **消费者**：{@see \Test\Module\Cron\Service\CronTaskManagerService::createNode} 校验必填项后
 * 写入 cron_agent_node 表。
 *
 * **关键字段语义**：
 * - nodeName / nodeIp：必填，空字符串会被 Service 拒绝
 * - remark：可选备注
 */
class CreateNodeDto extends AbstractDto
{
    #[ApiProperty(description: 'Agent 节点显示名称（必填）')]
    protected string $nodeName = '';

    #[ApiProperty(description: 'Agent 节点 IP 地址（必填）')]
    protected string $nodeIp = '';

    #[ApiProperty(description: '节点备注（可选）')]
    protected string $remark = '';

    /** 获取节点名称 */
    public function getNodeName(): string
    {
        return $this->nodeName;
    }

    /** 设置节点名称 */
    public function setNodeName(string $nodeName): static
    {
        $this->nodeName = $nodeName;

        return $this;
    }

    /** 获取节点 IP */
    public function getNodeIp(): string
    {
        return $this->nodeIp;
    }

    /** 设置节点 IP */
    public function setNodeIp(string $nodeIp): static
    {
        $this->nodeIp = $nodeIp;

        return $this;
    }

    /** 获取备注 */
    public function getRemark(): string
    {
        return $this->remark;
    }

    /** 设置备注 */
    public function setRemark(string $remark): static
    {
        $this->remark = $remark;

        return $this;
    }
}
