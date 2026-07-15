<?php

declare(strict_types=1);

namespace Test\Module\Cron\Dto\CronTaskManager;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Core\Dto\AbstractDto;

/**
 * 切换定时任务启用状态入参 DTO。
 *
 * **职责**：表达将指定任务设为启用或禁用的命令。
 *
 * **生产者**：{@see \Test\Module\Cron\Controller\CronTaskManagerController::switchTaskStatus}
 * 从 {@see \Test\Module\Cron\Request\CronTaskManager\CronTaskStatusSwitchRequest} 映射字段。
 *
 * **消费者**：{@see \Test\Module\Cron\Service\CronTaskManagerService::switchTaskStatus} 校验后
 * 更新数据库并返回 {@see TaskStatusAckDto}。
 *
 * **关键字段语义**：
 * - id：cron_task 主键
 * - status：0=禁用，1=启用（仅此二值合法）
 */
class SwitchTaskStatusDto extends AbstractDto
{
    #[ApiProperty(description: '目标任务 ID')]
    protected int $id = 0;

    #[ApiProperty(description: '目标状态：0=禁用，1=启用')]
    protected int $status = 0;

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

    /** 获取目标状态 */
    public function getStatus(): int
    {
        return $this->status;
    }

    /** 设置目标状态 */
    public function setStatus(int $status): static
    {
        $this->status = $status;

        return $this;
    }
}
