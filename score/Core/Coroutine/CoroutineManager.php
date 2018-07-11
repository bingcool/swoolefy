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

namespace Swoolefy\Core\Coroutine;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\BaseServer;

class CoroutineManager {

	use \Swoolefy\Core\SingletonTrait;

	/**
	 * coroutine_id的前缀
	 */
	const  PREFIX_CID = 'cid_';

	/**
	 * $cid 
	 * @var null
	 */
	protected static $cid = null;

	/**
	 * isEnableCoroutine 
	 * @return   boolean
	 */
	public  function canEnableCoroutine() {
		return BaseServer::canEnableCoroutine();
	}
	
	/**
	 * getMainCoroutineId 获取协程的id
	 * @return 
	 */
	public function getCoroutineId() {
		// 大于4.x版本,建议使用版本
		if($this->canEnableCoroutine()) {
			$cid = \co::getuid();
			// 在task|process中不直接支持使用协程
			if($cid == -1) {
				$cid = self::PREFIX_CID.'task_process';
			}else {
				$cid = self::PREFIX_CID.$cid;
			}
			return $cid;
		}else {
			// 1.x, 2.x版本不能使用协程，2.x编译时需要关闭协程选项
			if(isset(self::$cid) && !empty(self::$cid)) {
				return self::$cid;
			}
			$cid = (string)time().'_'.rand(1,999);
			self::$cid = self::PREFIX_CID.$cid;
			return self::$cid;
		}
	}

	/**
	 * getCoroutinStatus 
	 * @return   array
	 */
	public function getCoroutinStatus() {
		// 大于4.x版本
		if($this->canEnableCoroutine()) {
			if(method_exists('co', 'stats')) {
				return \co::stats();
			}
		}
		// 1.x, 2.x版本
		return null;
		
	}
}