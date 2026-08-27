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

    #[ApiProperty(description: 'register / running / success / failed / skipped / timeout / cancelled / unregister / unknown')]
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
        $dto = new self();
        $dto->taskId = (int) (self::pick($row, 'cron_id', 'cronId') ?? 0);
        $dto->execBatchId = (string) (self::pick($row, 'exec_batch_id', 'execBatchId') ?? '');
        $dto->pid = (int) (self::pick($row, 'pid') ?? 0);
        $dto->message = (string) (self::pick($row, 'message') ?? '');
        $statusCode = (int) (self::pick($row, 'status') ?? ExecutionStatus::REGISTER);
        $dto->statusCode = $statusCode;
        $dto->status = ExecutionStatus::name($statusCode);
        $dto->triggerType = (int) (self::pick($row, 'trigger_type', 'triggerType') ?? 0);
        $dto->scheduledAt = (string) (self::pick($row, 'scheduled_at', 'scheduledAt') ?? '');
        $started = (string) (self::pick($row, 'started_at', 'startedAt') ?? '');
        $finished = (string) (self::pick($row, 'finished_at', 'finishedAt') ?? '');
        $created = (string) (self::pick($row, 'created_at', 'createdAt') ?? '');
        $updated = (string) (self::pick($row, 'updated_at', 'updatedAt') ?? $created);
        $dto->startedAt = $started !== '' ? $started : $created;
        $dto->finishedAt = $finished !== '' ? $finished : $updated;
        $dto->durationMs = (float) (self::pick($row, 'duration_ms', 'durationMs') ?? 0);
        $exitCode = self::pick($row, 'exit_code', 'exitCode');
        $dto->exitCode = $exitCode !== null && $exitCode !== ''
            ? (int) $exitCode : null;
        $httpStatus = self::pick($row, 'http_status', 'httpStatus');
        $dto->httpStatus = $httpStatus !== null && $httpStatus !== ''
            ? (int) $httpStatus : null;
        $dto->taskItem = self::normalizeTaskItem(self::pick($row, 'task_item', 'taskItem'));

        return $dto;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function pick(array $row, string $snake, ?string $camel = null): mixed
    {
        if (array_key_exists($snake, $row)) {
            return $row[$snake];
        }
        if ($camel !== null && array_key_exists($camel, $row)) {
            return $row[$camel];
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function normalizeTaskItem(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }

            return ['raw' => $value];
        }

        return ['raw' => (string) $value];
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
