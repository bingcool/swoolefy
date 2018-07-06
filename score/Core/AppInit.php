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

use Swoolefy\Core\Init;
use Swoolefy\Core\Application;

class AppInit extends Init {
	/**
	 * _init 初始化一下超全局变量,兼容php-fpm的web模式
	 */
	public static function _init() {
		parent::_init();
		//请求对象
		$request = Application::getApp()->request;
		self::resetServer($request);
	}

	/**
	 * resetServer 重置SERVER请求对象
	 * @param  object  $request 请求对象
	 * @return void
	 */
	public static function resetServer($request) {
		foreach($request->server as $p=>$val) {
			$upper = strtoupper($p);
			$request->server[$upper] = $val;
			unset($request->server[$p]);
		}
		foreach($request->header as $key => $value) {
            $_key = 'HTTP_' . strtoupper(str_replace('-', '_', $key));
            $request->server[$_key] = $value;
        }

        $request->server = array_merge($_SERVER, $request->server);
	}

}
