<?php
/**
+----------------------------------------------------------------------
| swoolefy framework bases on swoole extension development, we can use it easily!
+----------------------------------------------------------------------
| Licensed ( https://opensource.org/licenses/MIT )
+----------------------------------------------------------------------
| @see https://github.com/bingcool/swoolefy
+----------------------------------------------------------------------
*/

namespace Swoolefy\Core\Controller;

use Swoole\Coroutine;
use Swoolefy\Core\Application;

class BController extends \Swoolefy\Core\AppObject {

    use \Swoolefy\Core\AppTrait,\Swoolefy\Core\ServiceTrait;

    /**
     * $request 当前请求的对象
     * @var \Swoole\Http\Request
     */
    public $request = null;

    /**
     * $response 当前请求的响应对象
     * @var \Swoole\Http\Response
     */
    public $response = null;

	/**
	 * $app_conf
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
		if(Coroutine::getCid() > 0) {
			defer(function() {
		    	$this->defer();
        	});
		}
	}

    /**
     * defer
     */
	public function defer() {}
}