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

use Swoolefy\Core\Runtime\Metrics\RuntimeMetrics;

/**
 * Cron 指标适配器：只接入现有 RuntimeMetrics，不另建第二套 Prometheus / 日志系统。
 *
 * $metrics 为 null（指标未启用）时全部 no-op，调用方不必分支。
 * 名称固定、不含 jobId / cron_name 标签，避免高基数。
 *
 * Gauge：jobs_total / jobs_enabled / jobs_running，由 CronManager::refreshMetrics() 刷新。
 * Counter：runs_total + success/failed/skipped。
 * Histogram：execution_duration；SKIPPED 不记耗时（未真正执行）。
 *
 * @see RuntimeMetrics::recordCronJobs()
 * @see CronManager::refreshMetrics()
 */
final class CronMetrics
{
    public function __construct(private readonly ?RuntimeMetrics $metrics)
    {
    }

    /**
     * 刷新 Job 数量类 Gauge（Registry 全量、可调度数、至少一实例在跑的数）。
     */
    public function recordJobs(int $total, int $enabled, int $running): void
    {
        $this->metrics?->recordCronJobs($total, $enabled, $running);
    }

    /**
     * 记录一次运行结果。status 为 ExecutionResult::SUCCESS / FAILED / SKIPPED。
     */
    public function recordRun(string $status, float $durationSeconds = 0.0): void
    {
        $this->metrics?->recordCronRun($status);
        if ($durationSeconds >= 0 && $status !== ExecutionResult::SKIPPED) {
            $this->metrics?->recordCronDuration($durationSeconds);
        }
    }
}
