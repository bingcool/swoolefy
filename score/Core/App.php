<?php
namespace Swoolefy\Core;

use Swoolefy\Core\Swfy;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Swoolefy\Tool\ArrayHelper\ArrayForHelp;
use Smarty;

class App {

	public static $app = null;

	public $request = null;

	public $response = null;

	public static $config = null;

	public static $num = 0;
	/**
	 * __construct
	 * @param 
	 */
	public function __construct(array $config=[]) {
		require(__DIR__."/../Websocket/Config/defines.php");

		self::$config = ArrayForHelp::merge(
			require(__DIR__."/../Websocket/Config/config.php"),$config
		);
		self::$app = $this;
		self::$num = (new Test)->setNum();
	}

	public function dispatch($request, $response) {
		var_dump((new Test)->setNum());
		// var_dump(Swfy::$server->setting['worker_num']);
		$response->end('<h3>hello!</h3>');

	}

}