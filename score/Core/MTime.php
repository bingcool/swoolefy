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

use Swoolefy\Core\Hook;

class MTime extends \Carbon\Carbon {

	/**
	 * __construct 初始化
	 */
	public function __construct() {
		
		// set hook call
		Hook::addHook(Hook::HOOK_AFTER_REQUEST, [$this,'clear']);
	}

	/**
	 * clear 在请求结束后要初始化这个静态变量
	 * @return   
	 */
	public static function clear() {
		self::$lastErrors = [];
	}
}