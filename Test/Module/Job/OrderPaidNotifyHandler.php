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

declare(strict_types=1);

namespace Test\Module\Job;

use Swoolefy\Support\Job\JobEnvelope;
use Swoolefy\Support\Job\JobHandlerInterface;
use Swoolefy\Support\Job\JobResult;

/**
 * Demo Handler：jobType=order.paid.notify。
 *
 * 幂等示意：生产代码应按 meta.idempotencyKey / orderId 做 upsert。
 */
final class OrderPaidNotifyHandler implements JobHandlerInterface
{
    public function types(): array
    {
        return ['order.paid.notify'];
    }

    public function handle(JobEnvelope $job): JobResult
    {
        $orderId = $job->payload['orderId'] ?? null;
        if (!is_int($orderId) && !(is_string($orderId) && ctype_digit($orderId))) {
            // 业务不可恢复错误 → FAIL → 死信（不再重试）
            return JobResult::fail('payload.orderId required');
        }

        // Demo/测试：forceRetry 时第 1 次投递模拟瞬时失败
        if (($job->payload['forceRetry'] ?? false) === true && $job->attempt < 2) {
            return JobResult::retry('demo transient failure');
        }

        // 生产侧此处应：按 idempotencyKey / orderId 幂等写库或发通知
        return JobResult::success();
    }
}
