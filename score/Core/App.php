<?php
namespace Swoolefy\Core;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\Application;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Swoolefy\Tool\ArrayHelper\ArrayForHelp;
use Swoolefy\Core\HttpRoute;

class App {
	/**
	 * $request 当前请求的对象
	 * @var null
	 */
	public $request = null;
	
	/**
	 * $response 当前请求的响应对象
	 * @var null
	 */
	public $response = null;

	/**
	 * $config 当前应用层的配置 
	 * @var null
	 */
	public $config = null;
	/**
	 * __construct
	 * @param $config 应用层配置
	 */
	public function __construct(array $config=[]) {
		include(__DIR__."/../Websocket/Config/defines.php");

		$this->config = ArrayForHelp::merge(
			require(__DIR__."/../Websocket/Config/config.php"),$config
		);
	}

	/**
	 * init 初始化函数
	 * @return void
	 */
	public function init() {
		// 初始化启动session
		if(!isset($this->config['session_start']) || (isset($this->config['session_start']) && $this->config['session_start'] === true)) {
			if(!isset($_SESSION)) {
				session_start();
			}
		}
	}

	/**
	 * run
	 * @param  $request
	 * @param  $response
	 * @return void
	 */
	public function run($request, $response) {
		$this->init();
		// 赋值对象
		$this->request = $request;
		$this->response = $response;

		// 判断是否是在维护模式
		if(!$this->catch()) {
			// 执行应用
			Application::$app = $this;
			$route = new HttpRoute();
			$route->dispatch();
		}
	}

	//使用trait的复用特性
	use \Swoolefy\Core\AppTrait;
}