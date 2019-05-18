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
     * @throws \Exception
	 */
	public function __call($action, $args = []) {
	    Application::getApp()->setEnd();
		Application::getApp()->response->end(json_encode([
			'ret' => 500,
			'msg' => "Calling unknown method: " . get_called_class()."::{$action}, ".json_encode($args, JSON_UNESCAPED_UNICODE),
			'data' => ''
		]));
		// 直接停止程序往下执行
		throw new \Exception("Calling unknown method: " . get_called_class()."::{$action}");
	}

	/**
	 * __callStatic
     * @throws \Exception
	 */
	public static function __callStatic($action, $args = []) {
        Application::getApp()->setEnd();
		Application::getApp()->response->end(json_encode([
			'ret' => 500,
			'msg' => "Calling unknown static method: " . get_called_class() . "::{$action}, ".json_encode($args, JSON_UNESCAPED_UNICODE),
			'data' => ''
		]));
		// 直接停止程序往下执行
		throw new \Exception("Calling unknown static method: " . get_called_class() . "::{$action}");
	}

	/**
	 * _die 异常终端程序执行
	 * @param    string $html
	 * @param    string $msg
     * @throws   \Exception
	 */
	public static function _die($html = '', $msg = '') {
		// 直接结束请求
		Application::getApp()->response->write($html);
		throw new \Exception($msg);
	}
}