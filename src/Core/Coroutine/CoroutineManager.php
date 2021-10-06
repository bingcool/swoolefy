<?php
/**
+----------------------------------------------------------------------
| swoolefy framework bases on swoole extension development, we can use it easily!
+----------------------------------------------------------------------
| Licensed ( https://opensource.org/licenses/MIT )
+----------------------------------------------------------------------
| @see https://github.com/bingcool/swoolefy
+----------------------------------------------------------------------
*/

namespace Swoolefy\Core\Coroutine;

class CoroutineManager {

	use \Swoolefy\Core\SingletonTrait;

	/**
	 * coroutine_id
	 */
	const  PREFIX_CID = '';

	/**
	 * isEnableCoroutine 
	 * @return bool
	 */
	public  function canEnableCoroutine() {
        return true;
	}
	
	/**
	 * getMainCoroutineId 获取协程的id
	 * @return int
	 */
	public function getCoroutineId() {
		// 大于4.2.x版本,建议使用版本
        $cid = \Swoole\Coroutine::getCid();
        // 4.3.0+在task|process中也支持直接使用协程,同时可以使用go()创建协程
        return $cid;
	}

    /**
     * @return bool
     */
	public function isCoroutine() {
	    if(\Swoole\Coroutine::getCid() > 0) {
            return true;
        }
        return false;
    }

	/**
	 * getCoroutineStatus
	 * @return array
	 */
	public function getCoroutineStatus() {
		// 大于4.x版本
		if($this->canEnableCoroutine()) {
			if(method_exists('Swoole\\Coroutine', 'stats')) {
				return \Swoole\Coroutine::stats();
			}
		}
		// 1.x, 2.x版本
		return null;
	}

	/**
	 * listCoroutines 遍历当前进程内的所有协程(swoole4.1.0+版本支持)
	 * @return array
	 */
	public function listCoroutines() {
		if(method_exists('Swoole\\Coroutine', 'list')) {
			$cids = [];
			$coros = \Swoole\Coroutine::list();
			foreach($coros as $cid) {
				array_push($cids, $cid);
			}
			return $cids;
		}
		return null;
	}

	/**
	 * getBackTrace
	 * @param   $cid  
	 * @param   $options
	 * @param   $limit
	 * @return  mixed
	 */
	public function getBackTrace($cid = 0, $options = DEBUG_BACKTRACE_PROVIDE_OBJECT, $limit = 0) {
		if(method_exists('Swoole\\Coroutine', 'getBackTrace')) {
			return \Swoole\Coroutine::getBackTrace($cid, $options,  $limit);
		}
		return null;
	}
}