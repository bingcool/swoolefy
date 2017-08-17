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
	/**
	 * __construct
	 * @param 
	 */
	public function __construct(array $config=[]) {
		require(__DIR__."/../Websocket/Config/defines.php");

		self::$config = ArrayForHelp::merge(
			require(__DIR__."/../Websocket/Config/config.php"),$config
		);

		var_dump(Swfy::$server->setting);

		self::$app = $this;
	}

	public function dispatch($request, $response) {
		self::$response = $response;
		self::$response = $response;

	}

}