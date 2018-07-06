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

namespace Swoolefy\Core\Model;

use Swoolefy\Core\Application;

class BModel extends \Swoolefy\Core\SModel {
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
	 * @var [type]
	 */
	public $session = null;

	/**
	 * __construct
	 */
	public function __construct() {
		parent::__construct();
		// 初始化请求对象和响应对象
		$this->request = Application::getApp()->request;
		$this->response = Application::getApp()->response;
		$this->session = Application::getApp()->session;
	}

	// model的多路复用trait
	use \Swoolefy\Core\ModelTrait, \Swoolefy\Core\AppObjectTrait;
}