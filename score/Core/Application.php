<?php
namespace Swoolefy\Core;

class Application {
	/**
	 * $app 应用对象
	 * @var null
	 */
	public static $app = null;

	/**
	 * __construct
	 */
	public function __construct() {
		self::$app = null;
	}

	/**
	 * isGet
	 * @return boolean
	 */
	public function isGet() {
		return (self::$app->request->server['request_method'] === 'GET') ? true :false;
	}

	/**
	 * isPost
	 * @return boolean
	 */
	public function isPost() {
		return (self::$app->request->server['request_method'] === 'POST') ? true :false;
	}

	/**
	 * isPut
	 * @return boolean
	 */
	public function isPut() {
		return (self::$app->request->server['request_method'] === 'PUT') ? true :false;
	}

	/**
	 * isDelete
	 * @return boolean
	 */
	public function isDelete() {
		return (self::$app->request->server['request_method'] === 'DELETE') ? true :false;
	}

	/**
	 * isAjax
	 * @return boolean
	 */
	public function isAjax() {
		return (isset(self::$app->request->header['x_requested_with']) && strtolower(self::$app->request->header['x_requested_with']) === 'xmlhttprequest') ? true : false;
	}

	/**
	 * __destruct
	 */
	public function __destruct() {
		self::$app = null;
	}
}