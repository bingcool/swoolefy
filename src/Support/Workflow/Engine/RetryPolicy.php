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

namespace Swoolefy\Support\Workflow\Engine;

/** 节点重试策略（全局默认或节点级 override）。 */
final class RetryPolicy
{
    public function __construct(
        /** 最大尝试次数（含首次）。 */
        public int $maxAttempts = 3,
        /** 首次重试延迟毫秒。 */
        public int $delayMs = 100,
        /** 指数退避乘数。 */
        public float $backoffMultiplier = 2.0,
    ) {
    }
}
