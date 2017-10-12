<?php
namespace Swoolefy\Core;

class Object {

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

}