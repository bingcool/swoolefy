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
	 * @return     void
	 */
	public static function addHook($type, $func, $prepend = false) {
		if($prepend) {
			array_unshift(self::$hooks[$type], $func);
		}else {
			self::$hooks[$type][] = $func;
		}
	}

	/**
	 * callhook 调用钩子函数
	 * @param   int $type
	 * @return  void
	 */
	public static function callHook($type) {
		if(isset(self::$hooks[$type])) {
			foreach(self::$hooks[$type] as $func) {
				$func();
			}
		}
		// init
		self::$hooks = [];
	}

	/**
	 * getHookCallable 获取所有的钩子函数
	 * @return  array
	 */
	public static function getHookCallable() {
		return self::$hooks;
	}
}