<?php

declare(strict_types=1);

namespace Test\Module\Cron\Dto\CronTaskManager;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Core\Dto\AbstractDto;

/**
 * 更新定时任务命令 DTO。
 *
 * **职责**：组合「目标任务 ID」与「待更新字段 payload」，表达一次部分更新操作。
 *
 * **生产者**：{@see \Test\Module\Cron\Controller\CronTaskManagerController::updateTask} 从
 * {@see \Test\Module\Cron\Request\CronTaskManager\CronTaskUpdateRequest} 映射 id 与 payload。
 *
 * **消费者**：{@see \Test\Module\Cron\Service\CronTaskManagerService::updateTask} 校验 id 存在后，
 * 将 payload 交给 PayloadBuilder（isCreate=false）做部分字段更新。
 *
 * **关键字段语义**：
 * - id：待更新任务的 cron_task 主键，必须 >0
 * - payload：仅包含本次提交的变更字段，空更新会被 Service 拒绝
 */
class UpdateTaskCommandDto extends AbstractDto
{
    #[ApiProperty(description: '待更新任务 ID')]
    protected int $id = 0;

    #[ApiProperty(description: '待更新字段的原始入参')]
    protected TaskPayloadInputDto $payload;

    public function __construct()
    {
        $this->payload = new TaskPayloadInputDto();
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

    /** 获取待更新字段 payload */
    public function getPayload(): TaskPayloadInputDto
    {
        return $this->payload;
    }

    /** 设置待更新字段 payload */
    public function setPayload(TaskPayloadInputDto $payload): static
    {
        $this->payload = $payload;

        return $this;
    }
}
