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

use Swoolefy\Core\Coroutine\CoroutineManager;

class Hook {
	/**
	 * $hooks 保存钩子执行函数
	 * @var array
	 */
	protected static $hooks = [];
 	const HOOK_AFTER_REQUEST = 1;

 	/**
	 * addHook 添加钩子函数
	 * @param    int   $type
	 * @param 	 mixed $func
	 * @param    boolean $prepend
	 * @return   boolean
	 */
	public static function addHook($type, $func, $prepend = false) {
		$cid = CoroutineManager::getInstance()->getCoroutineId();
		if(is_callable($func, true, $callable_name)) {
			$key = md5($callable_name);
			if($prepend) {
				if(!isset(self::$hooks[$cid][$type])) {
					self::$hooks[$cid][$type] = [];
				}
				// 防止重复设置
				if(!isset(self::$hooks[$cid][$type][$key])) {
					self::$hooks[$cid][$type] = array_merge([$key => $func], self::$hooks[$cid][$type]);
				}
				return true;
			}else {
				// 防止重复设置
				if(!isset(self::$hooks[$cid][$type][$key])) {
					self::$hooks[$cid][$type][$key] = $func;
				}
				return true;
			}
		}
		return false;
		
	}

	/**
	 * callhook 调用钩子函数
	 * @param   int $type
	 * @return  void
	 */
	public static function callHook($type, $cid = null) {
        if(empty($cid)) {
            $cid = CoroutineManager::getInstance()->getCoroutineId();
        }
		if(isset(self::$hooks[$cid][$type])) {
			foreach(self::$hooks[$cid][$type] as $func) {
				$func();
			}
		}
		// afterRequest钩子是目前应用实例生命周期最后执行的，直接将该协程的所有钩子函数都unset
        if($type == self::HOOK_AFTER_REQUEST && isset(self::$hooks[$cid])) {
            unset(self::$hooks[$cid]);
        }
	}

	/**
	 * getHookCallable 获取所有的钩子函数
     * @param int $cid
	 * @return  mixed
	 */
	public static function getHookCallable($cid = null) {
		if(empty($cid)) {
            $cid = CoroutineManager::getInstance()->getCoroutineId();
		}
		if(isset(self::$hooks[$cid])) {
            return self::$hooks[$cid];
        }
        return null;
	}
}