<?php

declare(strict_types=1);

namespace Test\Module\Cron\Dto\CronTaskManager;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Core\Dto\AbstractDto;

/**
 * 单 Agent 节点 ID 入参 DTO。
 *
 * **职责**：封装对单个 cron_agent_node 记录的主键引用，用于删除等仅需 id 的操作。
 *
 * **生产者**：{@see \Test\Module\Cron\Controller\CronTaskManagerController::deleteNode} 通过
 * {@see static::of} 从 Request 的 id 构造。
 *
 * **消费者**：{@see \Test\Module\Cron\Service\CronTaskManagerService::deleteNode} 校验 id 并软删（deleted_at）。
 *
 * **关键字段语义**：id 为 cron_agent_node 表主键，必须 >0 且未软删（deleted_at IS NULL）。
 */
class NodeIdDto extends AbstractDto
{
    #[ApiProperty(description: 'cron_agent_node 主键')]
    protected int $id = 0;

    /**
     * 快捷工厂：由节点 ID 构造 DTO。
     *
     * @param int $id cron_agent_node 主键
     */
    public static function of(int $id): self
    {
        $dto = new self();
        $dto->id = $id;

        return $dto;
    }

    /** 获取节点 ID */
    public function getId(): int
    {
        return $this->id;
    }

    /** 设置节点 ID */
    public function setId(int $id): static
    {
        $this->id = $id;

        return $this;
    }
}
