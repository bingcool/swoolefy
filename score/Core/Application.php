<?php
/**
+----------------------------------------------------------------------
| swoolefy framework bases on swoole extension development, we can use it easily!
+----------------------------------------------------------------------
| Licensed ( https://opensource.org/licenses/MIT )
+----------------------------------------------------------------------
| Author: bingcool <bingcoolhuang@gmail.com || 2437667702@qq.com>
+----------------------------------------------------------------------
*/

namespace Swoolefy\Core;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\Coroutine\CoroutineManager;

class Application {
	/**
	 * $app 应用对象
	 * @var null
	 */
	public static $app = null;

	/**
	 * $dump 记录启动时的调试打印信息
	 * @var null
	 */
	public static $dump = null;

	/**
	 * __construct
	 */
	public function __construct() {}

	/**
	 * setApp 
	 * @param $object
	 */
	public static function setApp($App) {
		if(Swfy::isWorkerProcess()) {
			// process进程将会定义成worker进程,ticker的callback应用实例必须继承ProcessController
			if($App instanceof \Swoolefy\Core\Process\ProcessController) {
				$cid = $App->coroutine_id;
				if(isset(self::$app[$cid])) {
					unset(self::$app[$cid]);
				}
				self::$app[$cid] = $App;
				return true;
			}
			
			// 在worker进程中进行,AppObject是http应用,swoole是rpc,websocket,udp应用，TickController是tick的回调应用
			if($App instanceof \Swoolefy\Core\AppObject || $App instanceof \Swoolefy\Core\Swoole || $App instanceof \Swoolefy\Core\Timer\TickController || $App instanceof \Swoolefy\Core\EventController) {
				$cid = $App->coroutine_id;
				if(isset(self::$app[$cid])) {
					unset(self::$app[$cid]);
				}
				self::$app[$cid] = $App;
				return true;
			}		 
		}else if(Swfy::isTaskProcess()) {
			// task中不创建协程，也不能使用协程,ticker的callback可以创建协程
			if($App instanceof \Swoolefy\Core\Timer\TickController) {
				$cid = $App->coroutine_id;
				if(isset(self::$app[$cid])) {
					unset(self::$app[$cid]);
				}
				self::$app[$cid] = $App;
				return true;
			}
			
			// http的task任务
			if($App instanceof \Swoolefy\Core\Task\TaskController) {
				$cid = $App->coroutine_id;
				if(isset(self::$app[$cid])) {
					unset(self::$app[$cid]);
				}
				self::$app[$cid] = $App;
				return true;
			}

			// rpc,websocket,udp的task
			if($App instanceof \Swoolefy\Core\Swoole) {
				$cid = $App->coroutine_id;
				if(isset(self::$app[$cid])) {
					unset(self::$app[$cid]);
				}
				self::$app[$cid] = $App;
				return true;
			}
		}
		
	}

	/**
	 * getApp 
	 * @param  int|null $coroutine_id
	 * @return $object
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
		$cid = CoroutineManager::getInstance()->getCoroutineId();
		if($coroutine_id) {
			$cid = $coroutine_id;
		}
		if(isset(self::$app[$cid])) {
			unset(self::$app[$cid]);
			return true;
		}
		return true;	
	} 

	/**
	 * __destruct
	 */
	public function __destruct() {}
}