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
 * 池外降级连接的 Worker 级并发配额租约。
 *
 * 为何必须绑在对象上，而不能在 ComponentTrait::get() 的 finally 里 --inflight：
 * get() 返回后连接仍被当前请求使用，额度要活到 clearComponent / __unset / DTO 析构。
 *
 * 为何必须幂等：
 * App::end() 会 clearComponent，随后 DTO 析构也会 release，只允许 --inflight 一次。
 *
 * 租约持有的是 {@see PoolsHandler}（Worker 单例），不是 App 实例；
 * inflight 才能跨请求累计，真正限制「当前进程同时在线的降级连接数」。
 */
final class PoolFallbackLease
{
    private bool $released = false;

    public function __construct(private readonly PoolsHandler $pool)
    {
    }

    /**
     * 归还一条降级额度。重复调用是空操作。
     */
    public function release(): void
    {
        if ($this->released) {
            return;
        }
        $this->released = true;
        $this->pool->releaseFallback();
    }

    /**
     * 是否已经还过额度（含析构触发的那一次）。
     */
    public function isReleased(): bool
    {
        return $this->released;
    }

    /**
     * 请求异常退出、未走 clearComponent 时的最后一道释放。
     */
    public function __destruct()
    {
        $this->release();
    }
}
