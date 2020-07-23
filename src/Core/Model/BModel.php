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

namespace Swoolefy\Core\Model;

use Swoolefy\Core\Application;

class BModel extends \Swoolefy\Core\SModel {

    use \Swoolefy\Core\ModelTrait, \Swoolefy\Core\AppObjectTrait;

    /**
	 * $request
	 * @var \Swoole\Http\Request
	 */
	public $request = null;

	/**
	 * $response 
	 * @var \Swoole\Http\Response
	 */
	public $response = null;

	/**
	 * __construct
	 */
	public function __construct() {
		parent::__construct();
		$app = Application::getApp();
		$this->request = $app->request;
		$this->response = $app->response;
	}
}