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

use Swoolefy\Core\Coroutine\CoroutineManager;

class Hook
{

    /**
     * hook after request type
     */
    const HOOK_AFTER_REQUEST = 1;

    /**
     * $hooks
     * @var array
     */
    protected static $hooks = [];

    /**
     * addHook
     * @param int $type
     * @param mixed $func
     * @param bool $prepend
     * @return bool
     */
    public static function addHook($type, $func, bool $prepend = false)
    {
        $cid = CoroutineManager::getInstance()->getCoroutineId();
        if (is_callable($func, true, $callable_name)) {
            $key = md5($callable_name);
            if ($prepend) {
                if (!isset(self::$hooks[$cid][$type])) {
                    self::$hooks[$cid][$type] = [];
                }
                if (!isset(self::$hooks[$cid][$type][$key])) {
                    self::$hooks[$cid][$type] = array_merge([$key => $func], self::$hooks[$cid][$type]);
                }
                return true;
            } else {
                if (!isset(self::$hooks[$cid][$type][$key])) {
                    self::$hooks[$cid][$type][$key] = $func;
                }
                return true;
            }
        }
        return false;

    }

    /**
     * call hooks
     * @param int $type
     * @param int $coroutineId
     * @return void
     */
    public static function callHook(int $type, ?int $coroutineId = null)
    {
        if (empty($coroutineId)) {
            $cid = CoroutineManager::getInstance()->getCoroutineId();
        }else {
            $cid = $coroutineId;
        }

        if (isset(self::$hooks[$cid][$type])) {
            foreach (self::$hooks[$cid][$type] as $func) {
                try {
                    $func();
                } catch (\Throwable $e) {
                    BaseServer::catchException($e);
                }
            }
        }

        if ($type == self::HOOK_AFTER_REQUEST && isset(self::$hooks[$cid])) {
            unset(self::$hooks[$cid]);
        }
    }

    /**
     * getHookCallable
     * @param int $coroutineId
     * @return callable
     */
    public static function getHookCallable(?int $coroutineId = null)
    {
        $cid = 0;
        if (empty($coroutineId)) {
            $cid = CoroutineManager::getInstance()->getCoroutineId();
        }

        return self::$hooks[$cid] ?? null;
    }
}