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
     * $app application object array
     * @var array
     */
    protected static $apps = [];

    /**
     * setApp
     * @param App|Swoole
     * @return bool
     * @throws \Exception
     */
    public static function setApp($App)
    {
        if (Swfy::isWorkerProcess()) {
            //worker进程中可以使用go()创建协程，和ticker的callback应用实例是支持协程的，controller必须继承父类EventController
            if ($App instanceof \Swoolefy\Core\EventController) {
                $cid = $App->getCid();
                if (isset(self::$apps[$cid])) {
                    unset(self::$apps[$cid]);
                }
                self::$apps[$cid] = $App;
                return true;
            }

            // 在worker进程中进行，AppObject是http应用,swoole是rpc,websocket,udp应用，TickController是tick的回调应用
            if ($App instanceof \Swoolefy\Core\AppObject || $App instanceof \Swoolefy\Core\Swoole) {
                $cid = $App->getCid();
                if (isset(self::$apps[$cid])) {
                    unset(self::$apps[$cid]);
                }
                self::$apps[$cid] = $App;
                return true;
            }

        } else if (Swfy::isTaskProcess()) {
            // task进程中，rpc,websocket,udp的task应用实例，没有产生协程id的，默认返回为-1，此时$App->coroutine_id等于cid_task_process
            if ($App instanceof \Swoolefy\Core\Swoole) {
                $cid = $App->getCid();
                if (isset(self::$apps[$cid])) {
                    unset(self::$apps[$cid]);
                }
                self::$apps[$cid] = $App;
                return true;
            }

            // task进程中，可以使用go创建协程和使用协程api
            if ($App instanceof \Swoolefy\Core\EventController || $App instanceof \Swoolefy\Core\Task\TaskController || $App instanceof \Swoolefy\Core\Timer\TickController) {
                $cid = $App->getCid();
                if (isset(self::$apps[$cid])) {
                    unset(self::$apps[$cid]);
                }
                self::$apps[$cid] = $App;
                return true;
            }
        } else {
            // process进程中，本身不产生协程，默认返回-1,可以通过设置第四个参数enable_coroutine = true启用协程
            // 同时可以使用go()创建协程，创建应用单例，单例继承于EventController类
            if ($App instanceof \Swoolefy\Core\EventController) {
                $cid = $App->getCid();
                if (isset(self::$apps[$cid])) {
                    unset(self::$apps[$cid]);
                }
                self::$apps[$cid] = $App;
                return true;
            }
        }
    }

    /**
     * issetApp
     * @param int $coroutine_id
     * @return bool
     */
    public static function issetApp($coroutine_id = null)
    {
        $cid = CoroutineManager::getInstance()->getCoroutineId();
        if ($coroutine_id) {
            $cid = $coroutine_id;
        }
        if (isset(self::$apps[$cid]) && self::$apps[$cid] instanceof EventController) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * getApp
     * @param int|null $coroutine_id
     * @return App|Swoole|EventController|null
     */
    public static function getApp(?int $coroutine_id = null)
    {
        $cid = CoroutineManager::getInstance()->getCoroutineId();
        if ($coroutine_id) {
            $cid = $coroutine_id;
        }
        return self::$apps[$cid] ?? null;
    }

    /**
     * removeApp
     * @param int|null $coroutine_id
     * @return bool
     */
    public static function removeApp(?int $coroutine_id = null)
    {
        if ($coroutine_id) {
            $cid = $coroutine_id;
        } else {
            $cid = CoroutineManager::getInstance()->getCoroutineId();
        }
        if (isset(self::$apps[$cid])) {
            unset(self::$apps[$cid]);
        }
        return true;
    }

    /**
     * @param int $ret
     * @param string $msg
     * @param string $data
     * @return array
     */
    public static function buildResponseData(int $ret = 0, string $msg = '', $data = '')
    {
        $responseFormatter = Swfy::getConf()['response_formatter'] ?? ResponseFormatter::class;
        return $responseFormatter::formatterData($ret, $msg, $data);
    }

    /**
     * __destruct
     */
    public function __destruct()
    {
    }
}