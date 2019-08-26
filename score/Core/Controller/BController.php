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
	 * $config
	 * @var null
	 */
	public $app_conf = null;

	/**
	 * __construct
	 * @param void
	 */
	public function __construct() {
		$app = Application::getApp();
		$this->request = $app->request;
		$this->response = $app->response;
		$this->app_conf = $app->app_conf;
		if(\Co::getCid() > 0) {
			defer(function() {
		    	$this->defer();
        	});
		}
	}

	/**
	 * __destruct 初始化一些静态变量
	 */
	public function defer() {
		static::_afterAction();
	}

	use \Swoolefy\Core\AppTrait,\Swoolefy\Core\ServiceTrait;
}