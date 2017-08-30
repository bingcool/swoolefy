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
	 * $route
	 * @var null
	 */
	public  $route = null;
	/**
	 * __construct
	 * @param $config 应用层配置
	 */
	public function __construct(array $config=[]) {
		$this->config = $config;
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

	/**
	 * catch 
	 * @return void
	 */
	public function catch() {
		// 获取配置信息
		if(isset($this->config['catch_all_info']) && $info = $this->config['catch_all_info']) {
			if(is_array($info)) {
				$this->response->header('Content-Type','application/json; charset=UTF-8');
				$this->response->end(json_encode($info));
			}else {
				$this->response->gzip(1);
				$this->response->header('Content-Type','text/html; charset=UTF-8');
				$this->response->end($info);
			}

			return true;
		}

		return false;
	}

	//使用trait的复用特性
	use \Swoolefy\Core\AppTrait;
}