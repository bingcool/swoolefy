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

use Swoolefy\Exception\SystemException;

class Application
{
    /**
     * application object array
     * @var array
     */
    protected static $apps = [];

    /**
     * @param App|Swoole|EventController $App
     * @return bool
     * @throws SystemException 当前 cid 已绑定其他实例时拒绝覆盖，提示改用 goApp()
     */
    public static function setApp(App|Swoole|EventController $App): bool
    {
        $cid = $App->getCid();
        self::$apps[$cid] = $App;
        return true;
    }

    /**
     * issetApp
     * @param int|null $coroutineId
     * @return bool
     */
    public static function issetApp($coroutineId = null): bool
    {
        $cid = \Swoole\Coroutine::getCid();
        if ($coroutineId !== null && $coroutineId !== false) {
            $cid = $coroutineId;
        }
        return isset(self::$apps[$cid]);
    }

    /**
     * getApp
     * @param int|null $coroutineId
     * @return App|Swoole|EventController|null
     */
    public static function getApp(?int $coroutineId = null): App|Swoole|EventController|null
    {
        $cid = \Swoole\Coroutine::getCid();
        if ($coroutineId !== null) {
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
        if ($coroutineId !== null) {
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
     * 测试/诊断用：当前已注册 Application 数量。
     */
    public static function countApps(): int
    {
        return count(self::$apps);
    }

    /**
     * __destruct
     */
    public function __destruct()
    {
    }
}
