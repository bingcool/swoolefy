<?php
namespace Swoolefy\Core;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$config = require_once __DIR__."/../App/Config/config.php";

class App {
	public $require_uri = null;

	static $test = null;

	global $name = "bingcool";
	/**
	 * __construct
	 * @param 
	 */
	public function __construct() {
		self::$test++;
		var_dump(self::$test);
	}

	public function dispatch($request, $response) {
		$num = 0;
		$this->test($num);

		$response->end("<h3>jjjjjjjjjjjjjjjjjjjjjj".$num."</h3>");
	}

	public function test(&$num) {
		return $num++;
	}

	public function __destruct() {
		parent::__destruct()
		self::$test = null;
	}
}