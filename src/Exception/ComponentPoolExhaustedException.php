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

namespace Swoolefy\Exception;

use Swoole\Http\Status;

/**
 * 组件连接池与 fallback 配额均已用尽。
 *
 * 继承 {@see SystemException}，全局异常处理按 `$throwable->getCode()` 写回 HTTP 503。
 */
final class ComponentPoolExhaustedException extends SystemException
{
    public function __construct(
        string $poolName,
        int $maxPoolNum,
        int $fallbackMax,
        int $fallbackInflight,
        int $code = Status::SERVICE_UNAVAILABLE,
        ?\Throwable $previous = null,
    ) {
        $totalCap = $maxPoolNum + $fallbackMax;
        parent::__construct(
            sprintf(
                '连接池[%s]已达到最大上限%d（池容量%d + 降级额度%d，当前降级占用%d）',
                $poolName,
                $totalCap,
                $maxPoolNum,
                $fallbackMax,
                $fallbackInflight,
            ),
            $code,
            $previous,
        );
        $this->setContextData([
            'pool' => $poolName,
            'max_pool_num' => $maxPoolNum,
            'fallback_max' => $fallbackMax,
            'fallback_inflight' => $fallbackInflight,
            'total_cap' => $totalCap,
        ]);
    }
}
