<?php

declare(strict_types=1);

namespace Test\Module\Cron\Dto\CronTaskManager;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Core\Dto\AbstractDto;

/**
 * 任务执行统计查询入参 DTO。
 *
 * **职责**：指定要统计哪条定时任务的执行日志聚合指标。
 *
 * **生产者**：{@see \Test\Module\Cron\Controller\CronTaskManagerController::taskStats} 通过
 * {@see static::of} 从 Request 的 taskId 构造。
 *
 * **消费者**：{@see \Test\Module\Cron\Service\CronTaskManagerService::taskStats} 扫描最近最多
 * 2000 条日志并返回 {@see CronTaskStatsResultDto}。
 *
 * **关键字段语义**：taskId 为 cron_task 主键，必须 >0。
 */
class TaskStatsQueryDto extends AbstractDto
{
    #[ApiProperty(description: '待统计的定时任务 ID')]
    protected int $taskId = 0;

    /**
     * 快捷工厂：由任务 ID 构造查询 DTO。
     *
     * @param int $taskId cron_task 主键
     */
    public static function of(int $taskId): self
    {
        $dto = new self();
        $dto->taskId = $taskId;

        return $dto;
    }

    /** 获取任务 ID */
    public function getTaskId(): int
    {
        return $this->taskId;
    }

    /** 设置任务 ID */
    public function setTaskId(int $taskId): static
    {
        $this->taskId = $taskId;

        return $this;
    }
}
