<?php
namespace Swoolefy\Core\Controller;

use Swoolefy\Core\Application;

class BController {
	/**
	 * $request
	 * @var null
	 */
	public $request = null;
	/**
	 * $response 
	 * @var null
	 */
	public $response = null;

	/**
	 * $config
	 * @var null
	 */
	public $config = null;

	public function __construct() {
		// 初始化请求对象和响应对象
		$this->request = Application::$app->request;
		$this->response = Application::$app->response;
		$this->config = Application::$app->config;
	}

	/**
	 * __call
	 * @return   [type]
	 */
	public function __call($action,$args = []) {
		if(SW_DEBUG) {
			$this->response->end('call method '.$action.' is not exist!');
		}else {
			$tpl404 = file_get_contents(TEMPLATE_PATH.DIRECTORY_SEPARATOR.$this->config['not_found_template']);
			$this->response->end($tpl404);
		}
		
	}

	//使用trait的复用特性
	use \Swoolefy\Core\AppTrait;
}