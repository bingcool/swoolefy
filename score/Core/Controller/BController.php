<?php
namespace Swoolefy\Core\Controller;

use Swoolefy\Core\Swfy;
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

	/**
	 * __construct
	 * @param    {String}
	 */
	public function __construct() {
		// 初始化请求对象和响应对象
		$this->request = Application::$app->request;
		$this->response = Application::$app->response;
		$this->config = Application::$app->config;
	}

	/**
	 * beforeAction 在处理实际action之前执行
	 * @return   mixed
	 */
	public function _beforeAction() {
		return true;
	}

	/**
	 * afterAction 在返回数据之前执行
	 * @return   mixed
	 */
	public function _afterAction() {
		
	}

	/**
	 * __call
	 * @return   mixed
	 */
	public function __call($action,$args = []) {
		$this->response->end(json_encode([
			'status' => 404,
			'msg' => 'Calling unknown method: ' . get_class($this) . "::$action()",
		]));
		// 直接停止往下执行
		throw new \Exception('Calling unknown method: ' . get_class($this) . "::$action()");
		
	}

	/**
	 * __destruct 返回数据之前执行
	 * @param    {String}
	 */
	public function __destruct() {
		if(method_exists($this,'_afterAction')) {
			static::_afterAction();
		}
		// 初始化这个变量
		static::$previousUrl = [];
	}

	//使用trait的复用特性
	use \Swoolefy\Core\AppTrait;
}