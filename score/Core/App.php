<?php
namespace Swoolefy\Core;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$config = require_once __DIR__."/../App/Config/config.php";

class App {
	public $require_uri = null;

	static $test = 6666;
	/**
	 * __construct
	 * @param 
	 */
	public function __construct() {
		self::$test++;
	}

	public function dispatch($request, $response) {
		var_dump(self::$test);
		$content = file_get_contents(__DIR__.'/../App/View/test.html');
		$response->end($content);
	}
}