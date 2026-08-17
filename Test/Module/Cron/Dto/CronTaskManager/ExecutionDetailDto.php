<?php

declare(strict_types=1);

namespace Test\Module\Cron\Dto\CronTaskManager;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Core\Dto\AbstractDto;

/**
 * 单次执行详情（由 cron_task_log 聚合）。
 */
class ExecutionDetailDto extends AbstractDto
{
    #[ApiProperty(description: '任务 ID')]
    protected int $taskId = 0;

    #[ApiProperty(description: '执行批次 ID')]
    protected string $execBatchId = '';

    #[ApiProperty(description: 'success / failed / skipped / unknown')]
    protected string $status = 'unknown';

    #[ApiProperty(description: '进程 PID')]
    protected int $pid = 0;

    #[ApiProperty(description: '开始时间')]
    protected string $startedAt = '';

    #[ApiProperty(description: '结束时间')]
    protected string $finishedAt = '';

    #[ApiProperty(description: '耗时毫秒')]
    protected float $durationMs = 0.0;

    /**
     * @var array<string, mixed>
     */
    #[ApiProperty(description: '执行时任务快照')]
    protected array $taskItem = [];

    #[ApiProperty(description: '日志原文')]
    protected string $message = '';

    /**
     * @param array<string, mixed> $row
     */
    public static function fromLogRow(array $row): self
    {
        $dto = new self();
        $dto->taskId = (int)($row['cron_id'] ?? 0);
        $dto->execBatchId = (string)($row['exec_batch_id'] ?? '');
        $dto->pid = (int)($row['pid'] ?? 0);
        $message = (string)($row['message'] ?? '');
        $dto->message = $message;
        $dto->status = self::classifyMessage($message);
        $created = (string)($row['created_at'] ?? '');
        $updated = (string)($row['updated_at'] ?? $created);
        $dto->startedAt = $created;
        $dto->finishedAt = $updated;
        $ti = $row['task_item'] ?? [];
        $dto->taskItem = is_array($ti) ? $ti : [];
        $dto->durationMs = self::extractDurationMs($message);

        return $dto;
    }

    public static function classifyMessage(string $message): string
    {
        $normalized = strtolower($message);
        if (str_contains($message, '跳过') || str_contains($message, '不能执行') || str_contains($normalized, 'skip')) {
            return 'skipped';
        }
        if (str_contains($message, '失败') || str_contains($message, '报错') || str_contains($normalized, 'error') || str_contains($normalized, 'fail')) {
            return 'failed';
        }
        if (str_contains($message, '成功') || str_contains($normalized, 'success')) {
            return 'success';
        }

        return 'unknown';
    }

    public static function extractDurationMs(string $message): float
    {
        if (preg_match('/(?:耗时|duration|cost)\\s*[:=]\\s*(\\d+(?:\\.\\d+)?)\\s*(ms|s)?/i', $message, $match)) {
            $value = (float)$match[1];
            $unit = strtolower((string)($match[2] ?? 'ms'));

            return $unit === 's' ? $value * 1000 : $value;
        }

        return 0.0;
    }
}
