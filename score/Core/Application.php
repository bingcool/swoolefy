<?php
namespace Swoolefy\Core;

class Application {
	
	public static $app = null;
	
	public function __construct() {
		self::$app = null;
	}

	public function __destruct() {
		self::$app = null;
	}
}