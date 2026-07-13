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

/** Demo Handler：jobType=order.export。 */
final class OrderExportHandler implements JobHandlerInterface
{
    public function types(): array
    {
        return ['order.export'];
    }

    public function handle(JobEnvelope $job): JobResult
    {
        $orderId = $job->payload['orderId'] ?? null;
        if ($orderId === null || $orderId === '') {
            return JobResult::fail('payload.orderId required');
        }

        // Demo：此处应生成导出文件 / 写对象存储等；需幂等
        return JobResult::success();
    }
}
