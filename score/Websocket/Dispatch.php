<?php
namespace Swoolefy\Websocket;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Smarty;


$config = require_once __DIR__."/../App/Config/config.php";

class Dispatch {

	public $require_uri = null;
	/**
	 * __construct
	 * @param 
	 */
	public function __construct() {
		
	}

	public function dispatch($request, $response) {

		$response->end('<h3>swoole is start</h3>');
	}
}