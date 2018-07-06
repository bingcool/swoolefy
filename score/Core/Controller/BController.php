<?php
/**
+----------------------------------------------------------------------
| swoolefy framework bases on swoole extension development, we can use it easily!
+----------------------------------------------------------------------
| Licensed ( https://opensource.org/licenses/MIT )
+----------------------------------------------------------------------
| Author: bingcool <bingcoolhuang@gmail.com || 2437667702@qq.com>
+----------------------------------------------------------------------
*/

namespace Swoolefy\Core\Controller;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\Application;

class BController extends \Swoolefy\Core\AppObject {
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
	 * $session
	 * @var null
	 */
	public $session = null;

	/**
	 * $config
	 * @var null
	 */
	public $config = null;

	/**
	 * __construct
	 * @param void
	 */
	public function __construct() {
		// 初始化请求对象和响应对象
		$this->request = Application::getApp()->request;
		$this->response = Application::getApp()->response;
		$this->session = Application::getApp()->session;
		$this->config = Application::getApp()->config;
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
	 * __destruct 返回数据之前执行,初始化一些静态变量
	 */
	public function __destruct() {
		if(method_exists($this,'_afterAction')) {
			static::_afterAction();
		}
	}

	//使用trait的复用特性
	use \Swoolefy\Core\AppTrait,\Swoolefy\Core\ServiceTrait;
}