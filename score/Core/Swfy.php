<?php
/**
+----------------------------------------------------------------------
| swoolfy framework bases on swoole extension development
+----------------------------------------------------------------------
| Licensed ( https://opensource.org/licenses/MIT )
+----------------------------------------------------------------------
| Author: bingcool <bingcoolhuang@gmail.com || 2437667702@qq.com>
+----------------------------------------------------------------------
*/

namespace Swoolefy\Core;

class Swfy extends \Swoolefy\Core\Object {

	use \Swoolefy\Core\ComponentTrait, \Swoolefy\Core\ServiceTrait;
	/**
	 * $server swoole服务超全局变量
	 * @var null
	 */
	public static $server = null;

	/**
	 * $Di Di容器components
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
	 * $com_alias_name 动态创建组件对象
	 * @param string $com_alias_name
	 * @param array  $defination
	 * @return void
	 */
	public static function createComponent($com_alias_name = null, array $defination = []) {
		return self::creatObject($com_alias_name, $defination);
	}

	/**
	 * clearComponent 清空Component
	 * @param  string  $com_alias_name
	 * @return void
	 */
	public static function clearComponent($com_alias_name = null) {
		return self::clearComponent($com_alias_name);
	}

	/**
	 * getComponent 获取组件
	 * @param [type] $com_alias_name
	 * @return void
	 */
	public static function getComponent($com_alias_name = null) {
		if($com_alias_name) {
			return self::$Di;
		}else {
			if(isset(self::$Di[$com_alias_name])) {
				return self::$Di[$com_alias_name];
			}
			return false;
		}	
	}

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