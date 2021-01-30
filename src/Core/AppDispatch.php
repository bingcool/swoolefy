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

class AppDispatch {
	/**
	 * $fileRouteMap 纯内存，无需每次请求判断is_file
	 * @var array
	 */
	protected static $routeCacheFileMap = [];

	/**
	 * __construct 
	 */
	public function __construct() {}
}