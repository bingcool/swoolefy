<?php

declare(strict_types=1);

namespace Test\Module\Cron\Dto\CronTaskManager;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Core\Dto\AbstractDto;

/**
 * 创建 Cron Agent 节点分组入参 DTO。
 *
 * **生产者**：{@see \Test\Module\Cron\Controller\CronTaskManagerController::createNodeGroup}
 * 从 {@see \Test\Module\Cron\Request\CronTaskManager\CronNodeGroupCreateRequest} 映射。
 *
 * **消费者**：{@see \Test\Module\Cron\Service\CronTaskManagerService::createNodeGroup}。
 */
class CreateNodeGroupDto extends AbstractDto
{
    #[ApiProperty(description: '分组名称（必填，唯一）')]
    protected string $groupName = '';

    #[ApiProperty(description: '备注（可选）')]
    protected string $remark = '';

    public function getGroupName(): string
    {
        return $this->groupName;
    }

    public function setGroupName(string $groupName): static
    {
        $this->groupName = $groupName;

        return $this;
    }

    public function getRemark(): string
    {
        return $this->remark;
    }

    public function setRemark(string $remark): static
    {
        $this->remark = $remark;

        return $this;
    }
}
