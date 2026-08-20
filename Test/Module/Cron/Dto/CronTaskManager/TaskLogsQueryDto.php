<?php

declare(strict_types=1);

namespace Test\Module\Cron\Dto\CronTaskManager;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Core\Dto\AbstractDto;

/**
 * 任务执行日志分页查询入参 DTO。
 *
 * **职责**：按任务 ID 分页查询 cron_task_log 执行记录。
 *
 * **生产者**：{@see \Test\Module\Cron\Controller\CronTaskManagerController::taskLogs} 从
 * {@see \Test\Module\Cron\Request\CronTaskManager\TaskLogsQueryRequest} 映射字段。
 *
 * **消费者**：{@see \Test\Module\Cron\Service\CronTaskManagerService::taskLogs} 按 cron_id 过滤
 * 并返回 {@see \Test\Module\Cron\Response\CronTaskManager\TaskLogsPageResult}。
 *
 * **关键字段语义**：
 * - taskId：关联的 cron_task 主键（对应日志表 cron_id 列），必须 >0
 * - page / pageSize：分页参数，setter 自动校正为 ≥1
 */
class TaskLogsQueryDto extends AbstractDto
{
    #[ApiProperty(description: '关联的定时任务 ID（cron_task_log.cron_id）')]
    protected int $taskId = 0;

    #[ApiProperty(description: '当前页码，从 1 开始')]
    protected int $page = 1;

    #[ApiProperty(description: '每页条数')]
    protected int $pageSize = 20;

    #[ApiProperty(description: '执行批次 ID，空表示不过滤')]
    protected ?string $execBatchId = null;

    #[ApiProperty(description: '结果状态：pending/running/success/failed/skipped/timeout/cancelled，空表示不过滤')]
    protected ?string $status = null;

    #[ApiProperty(description: '开始时间（含）')]
    protected ?string $startTime = null;

    #[ApiProperty(description: '结束时间（含）')]
    protected ?string $endTime = null;

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

    /** 获取当前页码 */
    public function getPage(): int
    {
        return $this->page;
    }

    /**
     * 设置当前页码（自动校正为 ≥1）。
     */
    public function setPage(int $page): static
    {
        $this->page = max(1, $page);

        return $this;
    }

    /** 获取每页条数 */
    public function getPageSize(): int
    {
        return $this->pageSize;
    }

    /**
     * 设置每页条数（自动校正为 ≥1）。
     */
    public function setPageSize(int $pageSize): static
    {
        $this->pageSize = max(1, $pageSize);

        return $this;
    }

    /**
     * 计算 SQL LIMIT 偏移量：(page - 1) × pageSize。
     */
    public function getOffset(): int
    {
        return ($this->getPage() - 1) * $this->getPageSize();
    }

    public function getExecBatchId(): ?string
    {
        return $this->execBatchId;
    }

    public function setExecBatchId(?string $execBatchId): static
    {
        $this->execBatchId = $execBatchId !== null && trim($execBatchId) !== '' ? trim($execBatchId) : null;

        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(?string $status): static
    {
        $this->status = $status !== null && trim($status) !== '' ? strtolower(trim($status)) : null;

        return $this;
    }

    public function getStartTime(): ?string
    {
        return $this->startTime;
    }

    public function setStartTime(?string $startTime): static
    {
        $this->startTime = $startTime !== null && trim($startTime) !== '' ? trim($startTime) : null;

        return $this;
    }

    public function getEndTime(): ?string
    {
        return $this->endTime;
    }

    public function setEndTime(?string $endTime): static
    {
        $this->endTime = $endTime !== null && trim($endTime) !== '' ? trim($endTime) : null;

        return $this;
    }
}
