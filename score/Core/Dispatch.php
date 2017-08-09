<?php
namespace Swoolefy\Core;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Smarty;


$config = require_once __DIR__."/../App/Config/config.php";

class Dispatch extends App {

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

		$response->end('<h3>swoole is start</h3>');
	}
}