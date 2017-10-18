<?php
namespace Swoolefy\Core\Model;

use Swoolefy\Core\Application;

class BModel extends \Swoolefy\Core\Component {
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
		return true;
	}

	/**
	 * __destruct 对象销毁前处理一些静态变量
	 * @param    
	 */
	public function __destruct() {
		static::_afterAction();
	}

	// model的多路复用trait
	use \Swoolefy\Core\ModelTrait;
}