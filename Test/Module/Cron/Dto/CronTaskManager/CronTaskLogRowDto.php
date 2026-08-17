<?php

declare(strict_types=1);

namespace Test\Module\Cron\Dto\CronTaskManager;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Core\Dto\AbstractDto;

/**
 * Cron 任务执行日志行 DTO。
 *
 * **职责**：表示 cron_task_log 表的单条执行记录，字段与数据库查询结果对齐（camelCase 输出）。
 *
 * **生产者**：{@see \Test\Module\Cron\Service\CronTaskManagerService::taskLogs} 通过
 * {@see static::fromEntityRow} 将实体行映射为 DTO；Agent 上报由 Service 直接写库。
 *
 * **消费者**：{@see \Test\Module\Cron\Response\CronTaskManager\TaskLogsPageResult} 收集列表项；
 * API 序列化时通过 ApiProperty 注解生成文档。
 *
 * **关键字段语义**：
 * - cronId：关联的 cron_task 主键
 * - execBatchId：同一次调度触发的批次号
 * - taskItem：执行时的任务元数据快照（JSON 列）
 * - message：执行结果描述，统计接口会解析其中的关键词与耗时
 */
class CronTaskLogRowDto extends AbstractDto
{
    #[ApiProperty(description: '日志 ID')]
    protected int $id = 0;

    #[ApiProperty(description: '关联任务 ID')]
    protected int $cronId = 0;

    #[ApiProperty(description: '执行批次 ID')]
    protected string $execBatchId = '';

    #[ApiProperty(description: '执行进程 PID')]
    protected int $pid = 0;

    /**
     * 任务项快照，执行时的调度元数据。
     *
     * @var array<string, mixed>|null
     */
    #[ApiProperty(description: '任务项快照')]
    protected ?array $taskItem = null;

    #[ApiProperty(description: '运行消息')]
    protected string $message = '';

    #[ApiProperty(description: '创建时间')]
    protected string $createdAt = '';

    #[ApiProperty(description: '更新时间')]
    protected string $updatedAt = '';

    /**
     * 从数据库实体行（snake_case）映射为 DTO。
     *
     * taskItem 列：数组原样保留；非数组非空值包装为 `['raw' => ...]`；空值置 null。
     *
     * @param array<string, mixed> $row cron_task_log 查询行
     */
    public static function fromEntityRow(array $row): self
    {
        $dto = new self();
        $dto->setId((int)($row['id'] ?? 0));
        $dto->setCronId((int)($row['cron_id'] ?? 0));
        $dto->setExecBatchId((string)($row['exec_batch_id'] ?? ''));
        $dto->setPid((int)($row['pid'] ?? 0));
        $ti = $row['task_item'] ?? null;
        if (is_array($ti)) {
            $dto->setTaskItem($ti);
        } elseif ($ti !== null && $ti !== '') {
            $dto->setTaskItem(['raw' => (string)$ti]);
        } else {
            $dto->setTaskItem(null);
        }
        $dto->setMessage((string)($row['message'] ?? ''));
        $dto->setCreatedAt((string)($row['created_at'] ?? ''));
        $dto->setUpdatedAt((string)($row['updated_at'] ?? ''));

        return $dto;
    }

    /** 获取日志 ID */
    public function getId(): int
    {
        return $this->id;
    }

    /** 设置日志 ID */
    public function setId(int $id): static
    {
        $this->id = $id;

        return $this;
    }

    /** 获取关联任务 ID */
    public function getCronId(): int
    {
        return $this->cronId;
    }

    /** 设置关联任务 ID */
    public function setCronId(int $cronId): static
    {
        $this->cronId = $cronId;

        return $this;
    }

    /** 获取执行批次 ID */
    public function getExecBatchId(): string
    {
        return $this->execBatchId;
    }

    /** 设置执行批次 ID */
    public function setExecBatchId(string $execBatchId): static
    {
        $this->execBatchId = $execBatchId;

        return $this;
    }

    /** 获取执行进程 PID */
    public function getPid(): int
    {
        return $this->pid;
    }

    /** 设置执行进程 PID */
    public function setPid(int $pid): static
    {
        $this->pid = $pid;

        return $this;
    }

    /**
     * 获取任务项快照。
     *
     * @return array<string, mixed>|null
     */
    public function getTaskItem(): ?array
    {
        return $this->taskItem;
    }

    /**
     * 设置任务项快照。
     *
     * @param array<string, mixed>|null $taskItem
     */
    public function setTaskItem(?array $taskItem): static
    {
        $this->taskItem = $taskItem;

        return $this;
    }

    /** 获取运行消息 */
    public function getMessage(): string
    {
        return $this->message;
    }

    /** 设置运行消息 */
    public function setMessage(string $message): static
    {
        $this->message = $message;

        return $this;
    }

    /** 获取创建时间 */
    public function getCreatedAt(): string
    {
        return $this->createdAt;
    }

    /** 设置创建时间 */
    public function setCreatedAt(string $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    /** 获取更新时间 */
    public function getUpdatedAt(): string
    {
        return $this->updatedAt;
    }

    /** 设置更新时间 */
    public function setUpdatedAt(string $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }
}
