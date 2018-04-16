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
	public function __construct() {
		
	}

	/**
	 * __destruct
	 */
	public function __destruct() {
	}
}