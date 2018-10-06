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

use Swoolefy\Core\Application;

class AppDispatch {
	/**
	 * $fileRouteMap 缓存请求类文件是否存在的map映射，纯内存，无需每次请求判断is_file
	 * @var array
	 */
	protected static $routeCacheFileMap = [];

	/**
	 * __construct 
	 */
	public function __construct() {
		
	}
}