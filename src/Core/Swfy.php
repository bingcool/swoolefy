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

namespace Swoolefy\Core;

class Swfy {

	use \Swoolefy\Core\ServiceTrait;

	/**
	 * $server swoole服务超全局变量
	 * @var \Swoole\Server
	 */
	public static $server = null;

	/**
	 * $conf swoole服务对应协议层的配置
	 * @var array
	 */
	public static $conf = [];
	
	/**
	 * $app_conf 应用层的配置
	 * @var array
	 */
	public static $app_conf = [];

	/**
	 * $com_alias_name 动态创建组件对象
	 * @param string $com_alias_name
	 * @param mixed  $definition
	 * @return mixed
	 */
	public static function createComponent(?string $com_alias_name, $definition = []) {
		return Application::getApp()->creatObject($com_alias_name, $definition);
	}

	/**
	 * removeComponent 销毁Component
	 * @param  string|array  $com_alias_name
	 * @return boolean
	 */
	public static function removeComponent(?string $com_alias_name = null) {
		return Application::getApp()->clearComponent($com_alias_name);
	}

	/**
	 * getComponent 获取组件
	 * @param  string  $com_alias_name
	 * @return mixed
	 */
	public static function getComponent(?string $com_alias_name = null) {
		return Application::getApp()->getComponents($com_alias_name);
	}

	/**
	 * __call
     * @return void
     * @throws \Exception
	 */
	public function __call($action, $args = []) {
		// stop exec
		throw new \Exception(sprintf(
		    "Calling unknown method: %s::%s",
                get_called_class(),
                $action
            )
        );
	}

	/**
	 * __callStatic
     * @return void
     * @throws \Exception
	 */
	public static function __callStatic($action, $args = []) {
		// stop exec
		throw new \Exception(sprintf(
		    "Calling unknown static method: %s::%s",
                get_called_class(),
                $action
            )
        );
	}

}