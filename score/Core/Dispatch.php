<?php
namespace Swoolefy\Core;

use Swoolefy\Core\Application;

class Dispatch {
	/**
	 * __construct
	 */
	public function __construct() {
		// 每一次请求清空
		$_COOKIE = [];
		$_POST = [];
		$_GET = [];
		$_REQUEST = [];

		$request = Application::$app->request;
		self::resetServer($request);
		self::resetPost($request);
		self::resetGet($request);
		self::resetCookie($request);
		self::resetFile($request);
		// 设置在最后执行
		self::resetRequest($request);
	}

	public static function resetServer($request) {
		$_SERVER = array_merge($_SERVER,$request->server);
	}

	public static function resetPost($request) {
		if(isset($request->post)) {
			$_POST = array_merge($_POST,$request->post);
		}
	}

	public static function resetGet($request) {
		if(isset($request->get)) {
			$_GET = array_merge($_GET,$request->get);
		}
	}

	public static function resetCookie($request) {
		if(isset($request->cookie)) {
			$_COOKIE = array_merge($_COOKIE,$request->cookie);
		}
	}

	public static function resetFile($request) {
		if(isset($request->fiels)) {
			$_FILES = array_merge($_FILES,$request->fiels);
		}
	}

	public static function resetRequest($request) {
		$_REQUEST = array_merge($_POST,$_GET,$_COOKIE);
	}
}