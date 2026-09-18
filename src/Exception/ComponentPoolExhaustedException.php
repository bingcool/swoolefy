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
 * 组件连接池与池外 fallback 配额均已用尽时抛出。
 *
 * 技术点：
 * - 继承 {@see SystemException}，`SwoolefyException::response()` 按 getCode()
 *   写 HTTP 状态；本异常默认 503，JSON `msg` 使用下方中文文案。
 * - 这是 backpressure，不是熔断：不探测下游健康，也不半开恢复。
 * - contextData 只含容量账本（别名/池容量/降级额度/占用），不含 DSN、连接对象。
 */
final class ComponentPoolExhaustedException extends SystemException
{
    /**
     * @param string $poolName         component_pools 别名（db / redis / cache 等）
     * @param int    $maxPoolNum       池内容量 max_pool_num
     * @param int    $fallbackMax      当前 Worker 允许的降级 inflight 上限
     * @param int    $fallbackInflight 抛出时已占用的降级数（拒绝路径通常已等于 max）
     */
    public function __construct(
        string $poolName,
        int $maxPoolNum,
        int $fallbackMax,
        int $fallbackInflight,
        int $code = Status::SERVICE_UNAVAILABLE,
        ?\Throwable $previous = null,
    ) {
        // 总上限 = 池内 + 池外，便于运维一眼看出「不是只撑满了 max_pool_num」
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
