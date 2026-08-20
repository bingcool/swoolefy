<?php
/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | @see https://github.com/bingcool/swoolefy
 * +----------------------------------------------------------------------
 */

namespace Swoolefy\Worker\Cron;

/**
 * cron_task_log 结构化执行状态（整数列 `status`）。
 *
 * message 只给人看；taskStats / Dashboard 必须用本常量 + SQL GROUP BY，
 * 禁止再从 message 推断成功/失败/跳过。
 *
 * 状态机：PENDING → RUNNING → SUCCESS|FAILED|TIMEOUT|CANCELLED；
 * 时间窗 / 重叠守卫拦截时 PENDING → SKIPPED（不进入 RUNNING）。
 */
final class ExecutionStatus
{
    public const PENDING = 0;
    public const RUNNING = 1;
    public const SUCCESS = 2;
    public const FAILED = 3;
    public const SKIPPED = 4;
    public const TIMEOUT = 5;
    public const CANCELLED = 6;

    /** 调度器按 expression / nextRunAt 触发 */
    public const TRIGGER_SCHEDULER = 1;

    /** 控制面 runOnceNow / 手动执行队列 */
    public const TRIGGER_RUN_ONCE = 2;

    /**
     * status 整型 → 统计键名。
     *
     * @var array<int, string>
     */
    public const NAMES = [
        self::PENDING => 'pending',
        self::RUNNING => 'running',
        self::SUCCESS => 'success',
        self::FAILED => 'failed',
        self::SKIPPED => 'skipped',
        self::TIMEOUT => 'timeout',
        self::CANCELLED => 'cancelled',
    ];

    /**
     * 空统计结构。无数据时也必须返回完整零值，避免前端缺键。
     *
     * @return array{
     *     total:int,pending:int,running:int,success:int,failed:int,skipped:int,
     *     timeout:int,cancelled:int,finished:int,attempted:int,
     *     successRate:float,avgDurationMs:float,maxDurationMs:float,samples:int
     * }
     */
    public static function emptyCounts(): array
    {
        return [
            'total' => 0,
            'pending' => 0,
            'running' => 0,
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
            'timeout' => 0,
            'cancelled' => 0,
            'finished' => 0,
            'attempted' => 0,
            'successRate' => 0.0,
            'avgDurationMs' => 0.0,
            'maxDurationMs' => 0.0,
            'samples' => 0,
        ];
    }

    /**
     * 将 DB GROUP BY status 行折叠为完整计数。
     *
     * 未知 status 只计入 total，不伪装成 pending。
     * 成功率分母 {@see attempted} = SUCCESS+FAILED+TIMEOUT+CANCELLED（不含 SKIPPED）。
     *
     * @param list<array<string, mixed>> $rows 每行至少含 status、total
     * @return array{
     *     total:int,pending:int,running:int,success:int,failed:int,skipped:int,
     *     timeout:int,cancelled:int,finished:int,attempted:int,
     *     successRate:float,avgDurationMs:float,maxDurationMs:float,samples:int
     * }
     */
    public static function aggregateCounts(array $rows): array
    {
        $stats = self::emptyCounts();
        foreach ($rows as $row) {
            $status = (int) ($row['status'] ?? -1);
            $count = (int) ($row['total'] ?? 0);
            $key = self::NAMES[$status] ?? null;
            if ($key !== null) {
                $stats[$key] = $count;
            }
            $stats['total'] += $count;
        }
        $stats['finished'] = $stats['success'] + $stats['failed'] + $stats['skipped']
            + $stats['timeout'] + $stats['cancelled'];
        $stats['attempted'] = $stats['success'] + $stats['failed']
            + $stats['timeout'] + $stats['cancelled'];
        $stats['successRate'] = $stats['attempted'] > 0
            ? round(($stats['success'] / $stats['attempted']) * 100, 2)
            : 0.0;

        return $stats;
    }

    /**
     * 填入 SUCCESS 行的耗时聚合（来自 AVG/MAX(duration_ms)，不是 message）。
     *
     * @param array<string, mixed> $stats
     * @return array<string, mixed>
     */
    public static function withDuration(array $stats, float $avgDurationMs, float $maxDurationMs, int $samples): array
    {
        $stats['avgDurationMs'] = $avgDurationMs;
        $stats['maxDurationMs'] = $maxDurationMs;
        $stats['samples'] = $samples;

        return $stats;
    }

    public static function name(int $status): string
    {
        return self::NAMES[$status] ?? 'unknown';
    }

    /**
     * 将 API 过滤值（success / 2 / SUCCESS）转为 status 整型；无法识别返回 null。
     */
    public static function fromName(string $name): ?int
    {
        $normalized = strtolower(trim($name));
        if ($normalized === '') {
            return null;
        }
        if (is_numeric($normalized)) {
            $status = (int) $normalized;

            return array_key_exists($status, self::NAMES) ? $status : null;
        }
        $map = array_flip(self::NAMES);

        return $map[$normalized] ?? null;
    }

    /**
     * ExecutionResult 字符串状态 → DB tinyint。未知结果记 FAILED，不伪装 PENDING。
     */
    public static function fromResult(ExecutionResult $result): int
    {
        return match ($result->status) {
            ExecutionResult::SUCCESS => self::SUCCESS,
            ExecutionResult::FAILED => self::FAILED,
            ExecutionResult::SKIPPED => self::SKIPPED,
            ExecutionResult::TIMEOUT => self::TIMEOUT,
            ExecutionResult::CANCELLED => self::CANCELLED,
            default => self::FAILED,
        };
    }

    public static function triggerType(string $source): int
    {
        return $source === 'runOnceNow' ? self::TRIGGER_RUN_ONCE : self::TRIGGER_SCHEDULER;
    }

    /**
     * 一次性历史迁移：仅在确认旧 message 格式时映射。
     *
     * 无法识别返回 null——调用方不得把 null 写成 PENDING。
     * 禁止用于 taskStats()。
     */
    public static function inferFromLegacyMessage(string $message): ?int
    {
        $trimmed = trim($message);
        if ($trimmed === '') {
            return null;
        }
        $normalized = strtolower($trimmed);

        if (preg_match('/】\s*SKIPPED\b/i', $trimmed) === 1 || preg_match('/】\s*SKIP\b/i', $trimmed) === 1) {
            return self::SKIPPED;
        }
        if (preg_match('/】\s*TIMEOUT\b/i', $trimmed) === 1) {
            return self::TIMEOUT;
        }
        if (preg_match('/】\s*CANCELLED\b/i', $trimmed) === 1) {
            return self::CANCELLED;
        }
        if (preg_match('/】\s*FAILED\b/i', $trimmed) === 1 || str_contains($trimmed, '执行异常已隔离')) {
            return self::FAILED;
        }
        if (preg_match('/】\s*SUCCESS\b/i', $trimmed) === 1) {
            return self::SUCCESS;
        }
        if ($normalized === 'success') {
            return self::SUCCESS;
        }
        if ($normalized === 'failed') {
            return self::FAILED;
        }
        if ($normalized === 'skipped') {
            return self::SKIPPED;
        }

        return null;
    }
}
