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

namespace Swoolefy\Core;

use Swoolefy\Core\Coroutine\Context;
use Swoolefy\Core\Coroutine\CoroutineManager;

class Hook
{

    /**
     * hook after request type
     */
    const HOOK_AFTER_REQUEST = 1;

    /**
     * cid => [type => [callableKey => callable]]
     * @var array
     */
    protected static $hooks = [];

    /**
     * cid => 注册时的协程 Context 对象 id。协程号复用后用来识别上一轮残留。
     * @var array<int, int>
     */
    protected static array $hookContext = [];

    /**
     * cid => 已经执行过 AFTER_REQUEST 的协程 Context。同一轮协程再注册会被拒绝。
     * @var array<int, int>
     */
    protected static array $hookSealed = [];

    /**
     * addHook
     * @param int $type
     * @param mixed $func
     * @param bool $prepend
     * @return bool
     */
    public static function addHook($type, $func, bool $prepend = false)
    {
        if (!is_callable($func)) {
            return false;
        }

        $cid = self::resolveCid(null);
        if (!self::acceptBucket($cid)) {
            return false;
        }

        $key = self::callableKey($func);
        $bucket = self::$hooks[$cid][$type] ?? [];
        if (!isset($bucket[$key])) {
            $bucket = $prepend ? [$key => $func] + $bucket : $bucket + [$key => $func];
        }
        self::$hooks[$cid][$type] = $bucket;

        return true;
    }

    /**
     * 同一个闭包、同一个实例方法只占一个名额。不同闭包、不同实例互不覆盖。
     */
    public static function callableKey(mixed $func): string
    {
        if ($func instanceof \Closure) {
            return 'closure:' . spl_object_id($func);
        }
        if (is_object($func)) {
            return 'invokable:' . spl_object_id($func);
        }
        if (is_array($func) && array_key_exists(0, $func) && array_key_exists(1, $func)) {
            $target = is_object($func[0]) ? 'object:' . spl_object_id($func[0]) : 'class:' . (string) $func[0];
            $method = is_string($func[1]) ? $func[1] : get_debug_type($func[1]);

            return $target . '::' . $method;
        }
        if (is_string($func)) {
            return 'func:' . $func;
        }

        return 'other:' . get_debug_type($func);
    }

    /**
     * call hooks
     * @param int $type
     * @param int $coroutineId
     * @return void
     */
    public static function callHook(int $type, ?int $coroutineId = null)
    {
        $cid = self::resolveCid($coroutineId);
        if (self::discardIfStale($cid)) {
            return;
        }

        $funcs = self::$hooks[$cid][$type] ?? [];
        if ($type === self::HOOK_AFTER_REQUEST) {
            unset(self::$hooks[$cid], self::$hookContext[$cid]);
            $contextId = self::coroutineContextId();
            if ($contextId !== 0) {
                self::$hookSealed[$cid] = $contextId;
                self::registerCoroutineCleanup($cid, $contextId);
            } else {
                unset(self::$hookSealed[$cid]);
            }
        }

        foreach ($funcs as $func) {
            try {
                call_user_func($func);
            } catch (\Throwable $e) {
                BaseServer::catchException($e);
            }
        }
    }

    /**
     * getHookCallable
     * @param int|null $coroutineId 显式传入时直接使用该 cid，不回退到其他协程
     * @return array 不存在时返回空集合
     */
    public static function getHookCallable(?int $coroutineId = null): array
    {
        $cid = self::resolveCid($coroutineId);
        if (self::isStale($cid)) {
            return [];
        }

        return self::$hooks[$cid] ?? [];
    }

    /**
     * 当前协程已经执行过 AFTER_REQUEST 时拒绝再登记。协程号被下一轮复用时先丢掉残留。
     */
    private static function acceptBucket(int $cid): bool
    {
        $contextId = self::coroutineContextId();
        if ($contextId !== 0 && isset(self::$hookSealed[$cid])) {
            if (self::$hookSealed[$cid] === $contextId) {
                return false;
            }
            unset(self::$hookSealed[$cid]);
        }
        if (self::isStale($cid)) {
            unset(self::$hooks[$cid], self::$hookContext[$cid], self::$hookSealed[$cid]);
        }
        if ($contextId !== 0) {
            self::$hookContext[$cid] = $contextId;
            self::registerCoroutineCleanup($cid, $contextId);
        }

        return true;
    }

    /**
     * 只在“当前协程号就是这只桶，但 Context 已经换过”时视为上一轮残留。
     * 显式传入别的 cid 时不丢，Swoole::end() 会在请求协程里按登记时的 cid 调用。
     */
    private static function discardIfStale(int $cid): bool
    {
        if (!self::isStale($cid)) {
            return false;
        }
        unset(self::$hooks[$cid], self::$hookContext[$cid], self::$hookSealed[$cid]);

        return true;
    }

    private static function isStale(int $cid): bool
    {
        if (!isset(self::$hookContext[$cid])) {
            return false;
        }
        if (CoroutineManager::getInstance()->getCoroutineId() !== $cid) {
            return false;
        }

        return self::$hookContext[$cid] !== self::coroutineContextId();
    }

    private static function resolveCid(?int $coroutineId): int
    {
        if ($coroutineId !== null) {
            return $coroutineId;
        }

        return CoroutineManager::getInstance()->getCoroutineId();
    }

    /**
     * 非协程返回 0。协程内用 Context 对象 id，协程号复用后这个 id 会变。
     */
    private static function coroutineContextId(): int
    {
        if (!class_exists(\Swoole\Coroutine::class) || \Swoole\Coroutine::getCid() < 0) {
            return 0;
        }

        $context = Context::getContext();
        if ($context === null) {
            return 0;
        }

        return spl_object_id($context);
    }

    /** 协程 Context 上标记：本协程已注册过 Hook 清理 defer。 */
    private const CTX_HOOK_CLEANUP = '__swoolefy_hook_cleanup_ctx';

    /**
     * 协程结束时释放 hooks / hookContext / hookSealed，避免 cid 单调递增时静态数组只增不减。
     * 同一协程内 AFTER_REQUEST 已 seal 后仍拒绝迟到注册，defer 在协程退出时才清 sealed。
     */
    private static function registerCoroutineCleanup(int $cid, int $contextId): void
    {
        if ($contextId === 0 || !class_exists(\Swoole\Coroutine::class)) {
            return;
        }

        $ctx = Context::getContext();
        if ($ctx === null) {
            return;
        }
        if (($ctx[self::CTX_HOOK_CLEANUP] ?? null) === $contextId) {
            return;
        }
        $ctx[self::CTX_HOOK_CLEANUP] = $contextId;

        \Swoole\Coroutine::defer(static function () use ($cid, $contextId): void {
            self::releaseCoroutineState($cid, $contextId);
        });
    }

    /**
     * 只清理仍属于该 contextId 的条目，避免误删已复用 cid 上的新协程状态。
     */
    private static function releaseCoroutineState(int $cid, int $contextId): void
    {
        if (isset(self::$hookSealed[$cid]) && self::$hookSealed[$cid] === $contextId) {
            unset(self::$hookSealed[$cid]);
        }
        if (isset(self::$hookContext[$cid]) && self::$hookContext[$cid] === $contextId) {
            unset(self::$hookContext[$cid], self::$hooks[$cid]);
        }
    }
}