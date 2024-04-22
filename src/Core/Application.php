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

class Application
{
    /**
     * application object array
     * @var array
     */
    protected static $apps = [];

    /**
     * @param App|Swoole|EventController $App
     * @return bool|void
     */
    public static function setApp(App|Swoole|EventController $App): bool
    {
        $closure = function ($appInstance) {
            $cid = $appInstance->getCid();
            if (isset(self::$apps[$cid])) {
                unset(self::$apps[$cid]);
            }
            self::$apps[$cid] = $appInstance;
            return true;
        };

        return $closure($App);
    }

    /**
     * issetApp
     * @param int $coroutineId
     * @return bool
     */
    public static function issetApp($coroutineId = null): bool
    {
        $cid = \Swoole\Coroutine::getCid();
        if ($coroutineId) {
            $cid = $coroutineId;
        }
        if (isset(self::$apps[$cid]) && self::$apps[$cid] instanceof EventController) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * getApp
     * @param int|null $coroutineId
     * @return App|Swoole|EventController|null
     */
    public static function getApp(?int $coroutineId = null): App|Swoole|EventController|null
    {
        $cid = \Swoole\Coroutine::getCid();
        if ($coroutineId) {
            $cid = $coroutineId;
        }
        return self::$apps[$cid] ?? null;
    }

    /**
     * removeApp
     * @param int|null $coroutineId
     * @return bool
     */
    public static function removeApp(?int $coroutineId = null): bool
    {
        if ($coroutineId) {
            $cid = $coroutineId;
        } else {
            $cid = \Swoole\Coroutine::getCid();
        }
        if (isset(self::$apps[$cid])) {
            unset(self::$apps[$cid]);
        }
        return true;
    }

    /**
     * __destruct
     */
    public function __destruct()
    {
    }
}