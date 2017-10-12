<?php
namespace Swoolefy\Core;

use Swoolefy\Core\Application;

class Dispatch {
	/**
	 * $fileRouteMap 请求类文件是否存在的map映射，纯内存，无需每次请求判断is_file
	 * @var array
	 */
	public static $RoutefileMap = [];

	/**
	 * __construct
	 */
	public function __construct() {
		// 每一次请求清空,再初始化
		$_COOKIE = [];
		$_POST = [];
		$_GET = [];
		$_REQUEST = [];
		//请求对象
		$request = Application::$app->request;
		self::resetServer($request);
		self::resetPost($request);
		self::resetGet($request);
		self::resetCookie($request);
		self::resetFile($request);
		// 设置在最后执行
		self::resetRequest($request);
	}
	/**
	 * resetServer重置SERVER超全局数组
	 * @param  $request 请求对象
	 * @return void
	 */
	public static function resetServer($request) {
		$_SERVER = array_merge($_SERVER,$request->server);
	}

	/**
	 * resetPost重置POST超全局数组
	 * @param  $request 请求对象
	 * @return void
	 */
	public static function resetPost($request) {
		if(isset($request->post)) {
			$_POST = array_merge($_POST,$request->post);
		}
	}

	/**
	 * resetGet重置GET超全局数组
	 * @param  $request 请求对象
	 * @return void
	 */
	public static function resetGet($request) {
		if(isset($request->get)) {
			$_GET = array_merge($_GET,$request->get);
		}
	}

	/**
	 * resetCookie
	 * @param  $request 请求对象
	 * @return void
	 */
	public static function resetCookie($request) {
		if(isset($request->cookie)) {
			$_COOKIE = array_merge($_COOKIE,$request->cookie);
		}
	}

	/**
	 * resetFile 
	 * @param  $request 请求对象
	 * @return void
	 */
	public static function resetFile($request) {
		if(isset($request->fiels)) {
			$_FILES = array_merge($_FILES,$request->fiels);
		}
	}

	/**
	 * resetRequest
	 * @param  $request 请求对象
	 * @return void
	 */
	public static function resetRequest($request) {
		$_REQUEST = array_merge($_POST,$_GET,$_COOKIE);
	}
}