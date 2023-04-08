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

class Application
{
    /**
     * application object array
     * @var array
     */
    protected static $apps = [];

    /**
     * setApp
     * @param App|Swoole|EventController
     * @return bool
     * @throws \Exception
     */
    public static function setApp($App)
    {
        $closure = function ($appInstance) {
            $cid = $appInstance->getCid();
            if (isset(self::$apps[$cid])) {
                unset(self::$apps[$cid]);
            }
            self::$apps[$cid] = $appInstance;
            return true;
        };

        if (Swfy::isWorkerProcess()) {
            if ($App instanceof \Swoolefy\Core\AppObject ||
                $App instanceof \Swoolefy\Core\EventController ||
                $App instanceof \Swoolefy\Core\Swoole

            ) {
               return $closure($App);
            }

        } else if (Swfy::isTaskProcess()) {
            if ($App instanceof \Swoolefy\Core\Swoole || $App instanceof \Swoolefy\Core\EventController) {
                return $closure($App);
            }
        } else {
            // process进程中,本身不产生协程,默认返回-1,可以通过设置第四个参数enable_coroutine = true启用协程
            // 同时可以使用go()创建协程,创建应用单例,单例继承于EventController类
            if ($App instanceof \Swoolefy\Core\EventController) {
                return $closure($App);
            }
        }
    }

    /**
     * issetApp
     * @param int $coroutineId
     * @return bool
     */
    public static function issetApp(int $coroutineId = null): bool
    {
        $cid = CoroutineManager::getInstance()->getCoroutineId();
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
    public static function getApp(?int $coroutineId = null)
    {
        $cid = CoroutineManager::getInstance()->getCoroutineId();
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
            $cid = CoroutineManager::getInstance()->getCoroutineId();
        }
        if (isset(self::$apps[$cid])) {
            unset(self::$apps[$cid]);
        }
        return true;
    }

    /**
     * @param int $code
     * @param string $msg
     * @param string $data
     * @return array
     */
    public static function buildResponseData(int $code = 0, string $msg = '', $data = '')
    {
        $responseFormatter = (!isset(Swfy::getConf()['response_formatter']) || empty(Swfy::getConf()['response_formatter'])) ? ResponseFormatter::class : Swfy::getConf()['response_formatter'];
        return $responseFormatter::formatterData($code, $msg, $data);
    }

    /**
     * __destruct
     */
    public function __destruct()
    {
    }
}