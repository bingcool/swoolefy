<?php

declare(strict_types=1);

namespace Test\Module\Cron\Dto\CronTaskManager;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Core\Dto\AbstractDto;

/**
 * 单节点分组 ID 入参 DTO。
 *
 * **职责**：封装对单个 cron_agent_node_group 记录的主键引用，用于详情 / 删除等仅需 id 的操作。
 *
 * **生产者**：{@see \Test\Module\Cron\Controller\CronTaskManagerController::getNodeGroup} /
 * {@see \Test\Module\Cron\Controller\CronTaskManagerController::deleteNodeGroup} 通过
 * {@see static::of} 从 Request 的 id 构造。
 *
 * **消费者**：{@see \Test\Module\Cron\Service\CronTaskManagerService::getNodeGroup} /
 * {@see \Test\Module\Cron\Service\CronTaskManagerService::deleteNodeGroup}。
 *
 * **关键字段语义**：id 为 cron_agent_node_group 表主键，必须 >0。
 */
class NodeGroupIdDto extends AbstractDto
{
    #[ApiProperty(description: 'cron_agent_node_group 主键')]
    protected int $id = 0;

    /**
     * @param int $id cron_agent_node_group 主键
     */
    public static function of(int $id): self
    {
        $dto = new self();
        $dto->id = $id;

        return $dto;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function setId(int $id): static
    {
        $this->id = $id;

        return $this;
    }
}
