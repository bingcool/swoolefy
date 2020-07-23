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
namespace Swoolefy\Core;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\App;
use Swoolefy\Core\Swoole;
use Swoolefy\Core\Coroutine\CoroutineManager;
use Swoolefy\Rpc\RpcHandler;
use Swoolefy\Udp\UdpHandler;
use Swoolefy\Websocket\WebsocketHandler;

class Application {
	/**
	 * $app 应用对象
	 * @var App|Swoole
	 */
	protected static $app = [];

    /**
     * setApp
     * @param App|Swoole
     * @return boolean
     * @throws \Exception
     */
	public static function setApp($App) {
		if(Swfy::isWorkerProcess()) {
			//worker进程中可以使用go()创建协程，和ticker的callback应用实例是支持协程的，controller必须继承TickController 或者父类ProcessController等
			if($App instanceof \Swoolefy\Core\Process\ProcessController) {
				$cid = $App->getCid();
				if(isset(self::$app[$cid])) {
					unset(self::$app[$cid]);
				}
				self::$app[$cid] = $App;
				return true;
			}
			
			// 在worker进程中进行，AppObject是http应用,swoole是rpc,websocket,udp应用，TickController是tick的回调应用
			if($App instanceof \Swoolefy\Core\AppObject || $App instanceof \Swoolefy\Core\Swoole || $App instanceof \Swoolefy\Core\Timer\TickController || $App instanceof \Swoolefy\Core\EventController) {
				$cid = $App->getCid();
				if(isset(self::$app[$cid])) {
					unset(self::$app[$cid]);
				}
				self::$app[$cid] = $App;
				return true;
			}

		}else if(Swfy::isTaskProcess()) {
			// task进程中，ticker的callback可以创建协程
			if($App instanceof \Swoolefy\Core\Timer\TickController) {
				$cid = $App->getCid();
				if(isset(self::$app[$cid])) {
					unset(self::$app[$cid]);
				}
				self::$app[$cid] = $App;
				return true;
			}

			// task进程中，http的task应用实例，没有产生协程id的，默认返回为-1，此时$App->coroutine_id等于cid_task_process
			if($App instanceof \Swoolefy\Core\Task\TaskController) {
				$cid = $App->getCid();
				if(isset(self::$app[$cid])) {
					unset(self::$app[$cid]);
				}
				self::$app[$cid] = $App;
				return true;
			}

			// task进程中，rpc,websocket,udp的task应用实例，没有产生协程id的，默认返回为-1，此时$App->coroutine_id等于cid_task_process
			if($App instanceof \Swoolefy\Core\Swoole) {
				$cid = $App->getCid();
				if(isset(self::$app[$cid])) {
					unset(self::$app[$cid]);
				}
				self::$app[$cid] = $App;
				return true;
			}

			// task进程中，swoole4.2.3版本起支持异步协程了，可以使用go创建协程和使用协程api
			if($App instanceof \Swoolefy\Core\EventController) {
				$cid = $App->getCid();
				if(isset(self::$app[$cid])) {
					unset(self::$app[$cid]);
				}
				self::$app[$cid] = $App;
				return true;
			}
		}else {
            // process进程中，本身不产生协程，默认返回-1,可以通过设置第四个参数enable_coroutine = true启用协程
            // 同时可以使用go()创建协程，创建应用单例，单例继承于ProcessController类
            if($App instanceof \Swoolefy\Core\Process\ProcessController || $App instanceof \Swoolefy\Core\EventController) {
                $cid = $App->getCid();
                if(isset(self::$app[$cid])) {
                    unset(self::$app[$cid]);
                }
                self::$app[$cid] = $App;
                return true;
            }
        }
	}

	/**
	 * issetApp
	 * @param  int $coroutine_id
	 * @return boolean 
	 */
	public static function issetApp($coroutine_id = null) {
		$cid = CoroutineManager::getInstance()->getCoroutineId();
		if($coroutine_id) {
			$cid = $coroutine_id;
		}
		if(isset(self::$app[$cid]) && self::$app[$cid] instanceof EventController) {
			return true;
		}else {
			return false;
		}
	}

	/**
	 * getApp 
	 * @param  int|null $coroutine_id
	 * @return Swoole|UdpHandler|WebsocketHandler|RpcHandler|App
	 */
	public static function getApp($coroutine_id = null) {
		$cid = CoroutineManager::getInstance()->getCoroutineId();
		if($coroutine_id) {
			$cid = $coroutine_id;
		}
		if(isset(self::$app[$cid])) {
			return self::$app[$cid];
		}else {
			return self::$app;
		}
	}

	/**
	 * removeApp 
	 * @param  int|null $coroutine_id
	 * @return boolean
	 */
	public static function removeApp($coroutine_id = null) {
		if($coroutine_id) {
			$cid = $coroutine_id;
		}else {
			$cid = CoroutineManager::getInstance()->getCoroutineId();
		}
		if(isset(self::$app[$cid])) {
			unset(self::$app[$cid]);
		}
		return true;	
	}

    /**
     * @param int $ret
     * @param string $msg
     * @param string $data
     * @return array
     */
	public static function buildResponseData($ret = 0, string $msg = '', $data = '') {
	    return [
            'ret' => $ret,
            'msg' => $msg,
            'data' => $data
        ];
    }

	/**
	 * __destruct
	 */
	public function __destruct() {}
}