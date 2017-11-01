<?php
namespace Swoolefy\Core;

use Swoolefy\Core\Application;

class Dispatch {
	/**
	 * $fileRouteMap 缓存请求类文件是否存在的map映射，纯内存，无需每次请求判断is_file
	 * @var array
	 */
	public static $routeFileMap = [];

	/**
	 * __construct 
	 */
	public function __construct() {
		
	}
}