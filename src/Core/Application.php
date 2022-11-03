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

        if (Swfy::isWorkerProcess()) {
            return $closure($App);
        } else if (Swfy::isTaskProcess()) {
            return $closure($App);
        } else {
            return $closure($App);
        }
    }

    /**
     * issetApp
     * @param int $coroutineId
     * @return bool
     */
    public static function issetApp($coroutineId = null): bool
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
    public static function getApp(?int $coroutineId = null): App|Swoole|EventController|null
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
     * @param mixed $data
     * @return array
     */
    public static function buildResponseData(
        int $code = 0,
        string $msg = '',
        mixed $data = []
    ): array
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