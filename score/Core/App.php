<?php
namespace Swoolefy\Core;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$config = require_once __DIR__."/../App/Config/config.php";

class App {
	public $require_uri = null;

	static $test = 0;
	/**
	 * __construct
	 * @param 
	 */
	public function __construct() {
		self::$test++;
		var_dump(self::$test);
	}

	public function dispatch($request, $response) {
		
		$response->end("<h3>jjjjjjjjjjjjjjjjjjjjjj</h3>");
	}
}