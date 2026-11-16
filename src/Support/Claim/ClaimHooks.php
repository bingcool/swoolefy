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

namespace Swoolefy\Support\Claim;

use Closure;

/**
 * 队列层面的观测钩子，用于指标。对应 gofy claim.Config.Hooks。
 *
 * 回调在发起操作的协程上同步执行，不得阻塞，也不要在这里操作同一条 claim 行。
 */
final class ClaimHooks
{
    /**
     * @param Closure(string $job, int $claimed): void|null $onClaim 每次认领后触发，包含认领 0 条的情况
     * @param Closure(string $job, int $reaped, int $dead): void|null $onReap 回收过期租约后触发
     * @param Closure(string $job): void|null $onLeaseLost 收尾时发现行已被接管时触发
     */
    public function __construct(
        public readonly ?Closure $onClaim = null,
        public readonly ?Closure $onReap = null,
        public readonly ?Closure $onLeaseLost = null,
    ) {
    }

    public function claim(string $job, int $claimed): void
    {
        if ($this->onClaim !== null) {
            ($this->onClaim)($job, $claimed);
        }
    }

    public function reap(string $job, int $reaped, int $dead): void
    {
        if ($this->onReap !== null) {
            ($this->onReap)($job, $reaped, $dead);
        }
    }

    public function leaseLost(string $job): void
    {
        if ($this->onLeaseLost !== null) {
            ($this->onLeaseLost)($job);
        }
    }
}
