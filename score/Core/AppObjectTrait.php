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

namespace Swoolefy\Core;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\Application;

trait AppObjectTrait {
	/**
	 * __call
	 * @return   mixed
	 */
	public function __call($action,$args = []) {
		Application::$app->response->end(json_encode([
			'status' => 404,
			'msg' => 'Calling unknown method: ' . get_called_class() . "::$action()",
		]));
		// 直接停止程序往下执行
		throw new \Exception('Calling unknown method: ' . get_called_class() . "::$action()");	
	}

	/**
	 * __callStatic
	 * @return   mixed
	 */
	public static function __callStatic($action,$args = []) {
		Application::$app->response->end(json_encode([
			'status' => 404,
			'msg' => 'Calling unknown static method: ' . get_called_class() . "::$action()",
		]));
		// 直接停止程序往下执行
		throw new \Exception('Calling unknown static method: ' . get_called_class() . "::$action()");
	}

	/**
	 * _die 异常终端程序执行
	 * @param    $msg
	 * @param    $code
	 * @return   mixed
	 */
	public static function _die($html='',$msg='') {
		// 直接结束请求
		Application::$app->response->end($html);
		throw new \Exception($msg);
	}
}