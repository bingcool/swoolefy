<?php
namespace Swoolefy\Core;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Smarty;

class App {

	/**
	 * [$test description]
	 * @var integer
	 */
	static $test = 6666;

	/**
	 * __construct
	 * @param 
	 */
	public function __construct(array $config=[]) {
		require(__DIR__."/../Websocket/Config/defines.php");

		$config = \Swoolefy\Tool\ArrayHelper\ArrayForHelp::merge(
			require(__DIR__."/../Websocket/Config/config.php"),$config
		);

		var_dump($config);
		
	}

	public function dispatch($request, $response) {
		var_dump(self::$test);
	}

}