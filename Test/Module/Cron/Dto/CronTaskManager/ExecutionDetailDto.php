<?php

declare(strict_types=1);

namespace Test\Module\Cron\Dto\CronTaskManager;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Core\Dto\AbstractDto;
use Swoolefy\Worker\Cron\ExecutionStatus;

/**
 * 单次执行详情（由 cron_task_log 结构化字段读取，不解析 message 推断状态）。
 */
class ExecutionDetailDto extends AbstractDto
{
    #[ApiProperty(description: '任务 ID')]
    protected int $taskId = 0;

    #[ApiProperty(description: '执行批次 ID')]
    protected string $execBatchId = '';

    #[ApiProperty(description: 'pending / running / success / failed / skipped / timeout / cancelled / unknown')]
    protected string $status = 'unknown';

    #[ApiProperty(description: 'status 整型')]
    protected int $statusCode = 0;

    #[ApiProperty(description: '触发类型：1-scheduler 2-run_once')]
    protected int $triggerType = 0;

    #[ApiProperty(description: '进程 PID')]
    protected int $pid = 0;

    #[ApiProperty(description: '计划执行时间')]
    protected string $scheduledAt = '';

    #[ApiProperty(description: '开始时间')]
    protected string $startedAt = '';

    #[ApiProperty(description: '结束时间')]
    protected string $finishedAt = '';

    #[ApiProperty(description: '耗时毫秒')]
    protected float $durationMs = 0.0;

    #[ApiProperty(description: 'Shell 退出码')]
    protected ?int $exitCode = null;

    #[ApiProperty(description: 'HTTP 状态码')]
    protected ?int $httpStatus = null;

    /**
     * @var array<string, mixed>
     */
    #[ApiProperty(description: '执行时任务快照')]
    protected array $taskItem = [];

    #[ApiProperty(description: '日志原文（人类可读）')]
    protected string $message = '';

    /**
     * @param array<string, mixed> $row
     */
    public static function fromLogRow(array $row): self
    {
        var_dump($row);
        $dto = new self();
        $dto->taskId = (int) ($row['cron_id'] ?? 0);
        $dto->execBatchId = (string) ($row['exec_batch_id'] ?? '');
        $dto->pid = (int) ($row['pid'] ?? 0);
        $dto->message = (string) ($row['message'] ?? '');
        $statusCode = (int) ($row['status'] ?? ExecutionStatus::PENDING);
        $dto->statusCode = $statusCode;
        $dto->status = ExecutionStatus::name($statusCode);
        $dto->triggerType = (int) ($row['trigger_type'] ?? 0);
        $dto->scheduledAt = (string) ($row['scheduled_at'] ?? '');
        $started = (string) ($row['started_at'] ?? '');
        $finished = (string) ($row['finished_at'] ?? '');
        $created = (string) ($row['created_at'] ?? '');
        $updated = (string) ($row['updated_at'] ?? $created);
        $dto->startedAt = $started !== '' ? $started : $created;
        $dto->finishedAt = $finished !== '' ? $finished : $updated;
        $dto->durationMs = (float) ($row['duration_ms'] ?? 0);
        $dto->exitCode = isset($row['exit_code']) && $row['exit_code'] !== null && $row['exit_code'] !== ''
            ? (int) $row['exit_code'] : null;
        $dto->httpStatus = isset($row['http_status']) && $row['http_status'] !== null && $row['http_status'] !== ''
            ? (int) $row['http_status'] : null;
        $ti = $row['task_item'] ?? [];
        $dto->taskItem = is_array($ti) ? $ti : [];
        var_dump($dto);
        return $dto;
    }

    public function getTaskId(): int
    {
        return $this->taskId;
    }

    public function getExecBatchId(): string
    {
        return $this->execBatchId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getTriggerType(): int
    {
        return $this->triggerType;
    }

    public function getPid(): int
    {
        return $this->pid;
    }

    public function getStartedAt(): string
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): string
    {
        return $this->finishedAt;
    }

    public function getDurationMs(): float
    {
        return $this->durationMs;
    }

    public function getExitCode(): ?int
    {
        return $this->exitCode;
    }

    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * @deprecated 统计与详情已改读 status 列；仅保留给历史单测对照。
     */
    public static function classifyMessage(string $message): string
    {
        $inferred = ExecutionStatus::inferFromLegacyMessage($message);

        return $inferred === null ? 'unknown' : ExecutionStatus::name($inferred);
    }

    /**
     * @deprecated 耗时已改读 duration_ms 列。
     */
    public static function extractDurationMs(string $message): float
    {
        if (preg_match('/(?:耗时|duration|cost)\\s*[:=]\\s*(\\d+(?:\\.\\d+)?)\\s*(ms|s)?/i', $message, $match)) {
            $value = (float) $match[1];
            $unit = strtolower((string) ($match[2] ?? 'ms'));

            return $unit === 's' ? $value * 1000 : $value;
        }

        return 0.0;
    }
}
