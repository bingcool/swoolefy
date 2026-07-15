<?php

declare(strict_types=1);

namespace Test\Module\Cron\Dto\CronTaskManager;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Core\Dto\AbstractDto;

/**
 * Agent 上报任务执行结果入参 DTO。
 *
 * **职责**：承载 Agent 单次任务执行完成后的日志写入数据。
 *
 * **生产者**：{@see \Test\Module\Cron\Controller\CronTaskManagerController::agentReport} 从
 * {@see \Test\Module\Cron\Request\CronTaskManager\CronAgentReportRequest} 映射字段。
 *
 * **消费者**：{@see \Test\Module\Cron\Service\CronTaskManagerService::agentReport} 校验后
 * 写入 cron_task_log 表。
 *
 * **关键字段语义**：
 * - cronId：被执行的定时任务 ID，必须 >0
 * - message：执行结果描述（成功/失败/跳过等），必须非空
 * - taskItem：任务元数据快照，支持数组或 JSON 字符串
 * - execBatchId：本轮执行批次 ID，用于关联同批多次上报
 * - pid：执行子进程 PID，无则 null
 */
class AgentReportDto extends AbstractDto
{
    #[ApiProperty(description: '被执行的定时任务 ID（cron_task 主键）')]
    protected int $cronId = 0;

    #[ApiProperty(description: '执行结果消息（成功/失败/跳过等文案）')]
    protected string $message = '';

    #[ApiProperty(description: '任务项快照，执行时的调度元数据')]
    protected mixed $taskItem = null;

    #[ApiProperty(description: '执行批次 ID，同一次调度触发共享同一批次号')]
    protected ?string $execBatchId = null;

    #[ApiProperty(description: '执行子进程 PID，shell 任务可能有值')]
    protected ?int $pid = null;

    /** 获取任务 ID */
    public function getCronId(): int
    {
        return $this->cronId;
    }

    /** 设置任务 ID */
    public function setCronId(int $cronId): static
    {
        $this->cronId = $cronId;

        return $this;
    }

    /** 获取执行消息 */
    public function getMessage(): string
    {
        return $this->message;
    }

    /** 设置执行消息 */
    public function setMessage(string $message): static
    {
        $this->message = $message;

        return $this;
    }

    /** 获取任务项快照 */
    public function getTaskItem(): mixed
    {
        return $this->taskItem;
    }

    /** 设置任务项快照 */
    public function setTaskItem(mixed $taskItem): static
    {
        $this->taskItem = $taskItem;

        return $this;
    }

    /** 获取执行批次 ID */
    public function getExecBatchId(): ?string
    {
        return $this->execBatchId;
    }

    /** 设置执行批次 ID */
    public function setExecBatchId(?string $execBatchId): static
    {
        $this->execBatchId = $execBatchId;

        return $this;
    }

    /** 获取子进程 PID */
    public function getPid(): ?int
    {
        return $this->pid;
    }

    /** 设置子进程 PID */
    public function setPid(?int $pid): static
    {
        $this->pid = $pid;

        return $this;
    }
}
