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

namespace Swoolefy\Core\Coroutine;

/**
 * 池耗尽后降级连接的进程内并发配额租约。
 *
 * release() 幂等：clearComponent 与 destructor 都可能调用，只允许 --inflight 一次。
 */
final class PoolFallbackLease
{
    private bool $released = false;

    public function __construct(private readonly PoolsHandler $pool)
    {
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        }
        $this->released = true;
        $this->pool->releaseFallback();
    }

    public function isReleased(): bool
    {
        return $this->released;
    }

    public function __destruct()
    {
        $this->release();
    }
}
