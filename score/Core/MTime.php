<?php
/**
+----------------------------------------------------------------------
| swoolfy framework bases on swoole extension development
+----------------------------------------------------------------------
| Licensed ( https://opensource.org/licenses/MIT )
+----------------------------------------------------------------------
| Author: bingcool <bingcoolhuang@gmail.com || 2437667702@qq.com>
+----------------------------------------------------------------------
*/

namespace Swoolefy\Core;

class MTime extends \Carbon\Carbon {
	/**
	 * clear 在请求结束后要初始化这个静态变量
	 * @return   
	 */
	public static function clear() {
		self::$lastErrors = [];
	}
}