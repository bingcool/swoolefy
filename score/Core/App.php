<?php
namespace Swoolefy\Core;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\Application;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Swoolefy\Tool\ArrayHelper\ArrayForHelp;
use Swoolefy\Core\HttpRoute;

class App {

	public $request = null;

	public $response = null;

	public $config = null;
	/**
	 * __construct
	 * @param 
	 */
	public function __construct(array $config=[]) {
		include(__DIR__."/../Websocket/Config/defines.php");

		$this->config = ArrayForHelp::merge(
			require(__DIR__."/../Websocket/Config/config.php"),$config
		);
		
	}

	public function init() {
		if(!isset($_SESSION)) {
			session_start();
		}
	}

	public function dispatch($request, $response) {
		$this->init();

		$this->request = $request;
		$this->response = $response;
		Application::$app = $this;
		
		$route = new HttpRoute();
		$route->invoke();
	}

}