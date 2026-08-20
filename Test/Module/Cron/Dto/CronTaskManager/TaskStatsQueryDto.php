<?php

declare(strict_types=1);

namespace Test\Module\Cron\Dto\CronTaskManager;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Core\Dto\AbstractDto;

/**
 * 任务执行统计查询入参 DTO。
 *
 * **职责**：指定要统计哪条定时任务，以及可选的半开时间窗。
 *
 * **生产者**：{@see \Test\Module\Cron\Controller\CronTaskManagerController::taskStats}
 *
 * **消费者**：{@see \Test\Module\Cron\Service\CronTaskManagerService::taskStats}
 * 使用 `created_at >= start AND created_at < end`，避免边界重复。
 *
 * **关键字段语义**：taskId 为 cron_task 主键，必须 >0。
 */
class TaskStatsQueryDto extends AbstractDto
{
    #[ApiProperty(description: '待统计的定时任务 ID')]
    protected int $taskId = 0;

    #[ApiProperty(description: '开始时间（含），空表示不限制')]
    protected ?string $start = null;

    #[ApiProperty(description: '结束时间（不含），空表示不限制')]
    protected ?string $end = null;

    /**
     * 快捷工厂：由任务 ID 与可选时间窗构造查询 DTO。
     *
     * @param int $taskId cron_task 主键
     */
    public static function of(int $taskId, ?string $start = null, ?string $end = null): self
    {
        $dto = new self();
        $dto->taskId = $taskId;
        $dto->setStart($start);
        $dto->setEnd($end);

        return $dto;
    }

    public function getTaskId(): int
    {
        return $this->taskId;
    }

    public function setTaskId(int $taskId): static
    {
        $this->taskId = $taskId;

        return $this;
    }

    public function getStart(): ?string
    {
        return $this->start;
    }

    public function setStart(?string $start): static
    {
        $this->start = $start !== null && trim($start) !== '' ? trim($start) : null;

        return $this;
    }

    public function getEnd(): ?string
    {
        return $this->end;
    }

    public function setEnd(?string $end): static
    {
        $this->end = $end !== null && trim($end) !== '' ? trim($end) : null;

        return $this;
    }
}
