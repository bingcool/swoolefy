<?php
namespace Swoolefy\Core;

class Swfy extends \Swoolefy\Core\Object {
	/**
	 * $server swoole服务超全局变量
	 * @var obj
	 */
	public static $server = null;

	/**
	 * $Di Di容器的静态变量数组
	 * @var array
	 */
	public static $Di = [];

	/**
	 * $config swoole服务对应协议层的配置,(注意：不是应用层的配置)
	 * @var null
	 */
	public static $config = [];
	/**
	 * $appConfig 应用层的配置
	 * @var null
	 */
	public static $appConfig = [];

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