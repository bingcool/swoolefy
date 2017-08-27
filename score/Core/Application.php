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
	 * __destruct
	 */
	public function __destruct() {
		self::$app = null;
	}
}