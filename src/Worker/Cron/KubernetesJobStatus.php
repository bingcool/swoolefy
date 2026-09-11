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
 * 把 Kubernetes Job.status 映射成 Execution 终态（方案 §12）。
 *
 * Executor 轮询与 Crash Recovery 必须用同一套判定，否则会出现
 * 「Executor 认为 Complete、Recovery 写成 WORKER_CRASH」这种分叉。
 */
final class KubernetesJobStatus
{
    /**
     * @param array<string, mixed> $job
     * @return array{0:string,1:string}|null [ExecutionResult 状态, 原因]；null = 仍在跑
     */
    public static function classify(array $job): ?array
    {
        $status = is_array($job['status'] ?? null) ? $job['status'] : [];
        if ((int) ($status['succeeded'] ?? 0) >= 1) {
            return [ExecutionResult::SUCCESS, 'Job Complete'];
        }

        foreach ((array) ($status['conditions'] ?? []) as $condition) {
            if (!is_array($condition) || (string) ($condition['status'] ?? '') !== 'True') {
                continue;
            }
            $type = (string) ($condition['type'] ?? '');
            $reason = (string) ($condition['reason'] ?? '');
            if ($type === 'Complete') {
                return [ExecutionResult::SUCCESS, 'Job Complete'];
            }
            if ($type === 'Failed') {
                $detail = trim($reason . ' ' . (string) ($condition['message'] ?? ''));

                return $reason === 'DeadlineExceeded'
                    ? [ExecutionResult::TIMEOUT, 'Job DeadlineExceeded ' . $detail]
                    : [ExecutionResult::FAILED, 'Job Failed ' . $detail];
            }
        }

        if ((int) ($status['failed'] ?? 0) >= 1) {
            return [ExecutionResult::FAILED, 'Job Failed'];
        }

        return null;
    }
}
