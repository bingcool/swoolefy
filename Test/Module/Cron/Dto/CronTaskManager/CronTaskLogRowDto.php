<?php

declare(strict_types=1);

namespace Test\Module\Cron\Dto\CronTaskManager;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Core\Dto\AbstractDto;

/**
 * Cron 任务执行日志行。
 */
class CronTaskLogRowDto extends AbstractDto
{
    #[ApiProperty(description: '日志 ID')]
    protected int $id = 0;

    #[ApiProperty(description: '关联任务 ID')]
    protected int $cronId = 0;

    #[ApiProperty(description: '执行批次 ID')]
    protected string $execBatchId = '';

    /**
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
     * @param array<string, mixed> $row
     */
    public static function fromEntityRow(array $row): self
    {
        $dto = new self();
        $dto->setId((int)($row['id'] ?? 0));
        $dto->setCronId((int)($row['cron_id'] ?? 0));
        $dto->setExecBatchId((string)($row['exec_batch_id'] ?? ''));
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

    public function getId(): int
    {
        return $this->id;
    }

    public function setId(int $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function getCronId(): int
    {
        return $this->cronId;
    }

    public function setCronId(int $cronId): static
    {
        $this->cronId = $cronId;

        return $this;
    }

    public function getExecBatchId(): string
    {
        return $this->execBatchId;
    }

    public function setExecBatchId(string $execBatchId): static
    {
        $this->execBatchId = $execBatchId;

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getTaskItem(): ?array
    {
        return $this->taskItem;
    }

    /**
     * @param array<string, mixed>|null $taskItem
     */
    public function setTaskItem(?array $taskItem): static
    {
        $this->taskItem = $taskItem;

        return $this;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function setMessage(string $message): static
    {
        $this->message = $message;

        return $this;
    }

    public function getCreatedAt(): string
    {
        return $this->createdAt;
    }

    public function setCreatedAt(string $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): string
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(string $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }
}
