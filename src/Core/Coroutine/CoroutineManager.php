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

namespace Swoolefy\Core\Coroutine;

class CoroutineManager
{

    use \Swoolefy\Core\SingletonTrait;


    /**
     * isEnableCoroutine
     * @return bool
     */
    public function canEnableCoroutine()
    {
        return true;
    }

    /**
     * getMainCoroutineId
     * @return int
     */
    public function getCoroutineId()
    {
        $cid = \Swoole\Coroutine::getCid();
        return $cid;
    }

    /**
     * @return bool
     */
    public function isCoroutine()
    {
        if (\Swoole\Coroutine::getCid() > 0) {
            return true;
        }
        return false;
    }

    /**
     * getCoroutineStatus
     * @return array
     */
    public function getCoroutineStatus()
    {
        if ($this->canEnableCoroutine()) {
            if (method_exists('Swoole\\Coroutine', 'stats')) {
                return \Swoole\Coroutine::stats();
            }
        }
        return null;
    }

    /**
     * listCoroutines
     * @return array
     */
    public function listCoroutines()
    {
        if (method_exists('Swoole\\Coroutine', 'list')) {
            $cids = [];
            $coros = \Swoole\Coroutine::list();
            foreach ($coros as $cid) {
                array_push($cids, $cid);
            }
            return $cids;
        }
        return null;
    }

    /**
     * getBackTrace
     * @param $cid
     * @param $options
     * @param $limit
     * @return mixed
     */
    public function getBackTrace($cid = 0, $options = DEBUG_BACKTRACE_PROVIDE_OBJECT, $limit = 0)
    {
        if (method_exists('Swoole\\Coroutine', 'getBackTrace')) {
            return \Swoole\Coroutine::getBackTrace($cid, $options, $limit);
        }
        return null;
    }
}