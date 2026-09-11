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

namespace Swoolefy\Worker\Kubernetes;

/**
 * 把 Kubernetes Job.status 判定为集群侧终态。
 *
 * 只认识 Job 对象，不引用 Cron 的 ExecutionResult。Executor / Recovery 各自映射。
 */
final class JobStatus
{
    public const COMPLETE = 'complete';

    public const FAILED = 'failed';

    public const DEADLINE_EXCEEDED = 'deadline_exceeded';

    /**
     * @param array<string, mixed> $job
     * @return array{0:string,1:string}|null [本类常量, 原因]；null = 仍在跑
     */
    public static function classify(array $job): ?array
    {
        $status = is_array($job['status'] ?? null) ? $job['status'] : [];
        if ((int) ($status['succeeded'] ?? 0) >= 1) {
            return [self::COMPLETE, 'Job Complete'];
        }

        foreach ((array) ($status['conditions'] ?? []) as $condition) {
            if (!is_array($condition) || (string) ($condition['status'] ?? '') !== 'True') {
                continue;
            }
            $type = (string) ($condition['type'] ?? '');
            $reason = (string) ($condition['reason'] ?? '');
            if ($type === 'Complete') {
                return [self::COMPLETE, 'Job Complete'];
            }
            if ($type === 'Failed') {
                $detail = trim($reason . ' ' . (string) ($condition['message'] ?? ''));

                return $reason === 'DeadlineExceeded'
                    ? [self::DEADLINE_EXCEEDED, 'Job DeadlineExceeded ' . $detail]
                    : [self::FAILED, 'Job Failed ' . $detail];
            }
        }

        if ((int) ($status['failed'] ?? 0) >= 1) {
            return [self::FAILED, 'Job Failed'];
        }

        return null;
    }
}
