<?php

declare(strict_types=1);

namespace Test\Module\Cron\Dto\CronTaskManager;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Core\Dto\AbstractDto;

/**
 * 单任务 ID 入参 DTO。
 *
 * **职责**：封装对单个 cron_task 记录的主键引用，用于删除等仅需 id 的操作。
 *
 * **生产者**：{@see \Test\Module\Cron\Controller\CronTaskManagerController::deleteTask} 通过
 * {@see static::of} 从 Request 的 id 构造。
 *
 * **消费者**：{@see \Test\Module\Cron\Service\CronTaskManagerService::deleteTask} 校验 id 并执行删除。
 *
 * **关键字段语义**：id 为 cron_task 表主键，必须 >0 且记录存在。
 */
class TaskIdDto extends AbstractDto
{
    #[ApiProperty(description: 'cron_task 主键')]
    protected int $id = 0;

    /**
     * 快捷工厂：由任务 ID 构造 DTO。
     *
     * @param int $id cron_task 主键
     */
    public static function of(int $id): self
    {
        $dto = new self();
        $dto->id = $id;

        return $dto;
    }

    /** 获取任务 ID */
    public function getId(): int
    {
        return $this->id;
    }

    /** 设置任务 ID */
    public function setId(int $id): static
    {
        $this->id = $id;

        return $this;
    }
}
