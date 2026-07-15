<?php

declare(strict_types=1);

namespace Test\Module\Cron\Dto\CronTaskManager;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Core\Dto\AbstractDto;

/**
 * 切换任务启用状态确认结果 DTO。
 *
 * **职责**：回传状态切换操作后的任务 ID 与最新状态值。
 *
 * **生产者**：{@see \Test\Module\Cron\Service\CronTaskManagerService::switchTaskStatus} 更新
 * 数据库后通过 {@see static::of} 构造。
 *
 * **消费者**：{@see \Test\Module\Cron\Controller\CronTaskManagerController::switchTaskStatus}
 * 读取 id / status 并组装 {@see \Test\Module\Cron\Response\CronTaskManager\CronTaskStatusAckResponse}。
 *
 * **关键字段语义**：
 * - id：已更新状态的任务 ID
 * - status：更新后的状态值，0=禁用，1=启用
 */
class TaskStatusAckDto extends AbstractDto
{
    #[ApiProperty(description: '已更新状态的任务 ID')]
    protected int $id = 0;

    #[ApiProperty(description: '更新后的状态：0=禁用，1=启用')]
    protected int $status = 0;

    /**
     * 快捷工厂：由任务 ID 与新状态构造确认 DTO。
     *
     * @param int $id 任务 ID
     * @param int $status 新状态（0 或 1）
     */
    public static function of(int $id, int $status): self
    {
        $dto = new self();
        $dto->id = $id;
        $dto->status = $status;

        return $dto;
    }

    /** 获取任务 ID */
    public function getId(): int
    {
        return $this->id;
    }

    /** 获取更新后的状态 */
    public function getStatus(): int
    {
        return $this->status;
    }
}
