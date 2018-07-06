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

namespace Swoolefy;
// 定义当前core的根路径
defined('SWOOLEFY_CORE_ROOT_PATH') or define('SWOOLEFY_CORE_ROOT_PATH', __DIR__.'/Core');

// 定义打包检查类型
defined('SWOOLEFY_PACK_CHECK_LENGTH') or define('SWOOLEFY_PACK_CHECK_LENGTH', 'length');
defined('SWOOLEFY_PACK_CHECK_EOF') or define('SWOOLEFY_PACK_CHECK_EOF', 'eof');

// 定义服务协议常量
defined('SWOOLEFY_HTTP') or define('SWOOLEFY_HTTP', 'http');
defined('SWOOLEFY_WEBSOCKET') or define('SWOOLEFY_WEBSOCKET', 'websocket');
defined('SWOOLEFY_TCP') or define('SWOOLEFY_TCP', 'tcp');
defined('SWOOLEFY_UDP') or define('SWOOLEFY_UDP', 'udp');

// 定义组件可以选择使用的属性key
defined('SWOOLEFY_COM_IS_DELAY') or define('SWOOLEFY_COM_IS_DELAY', 'is_delay');
defined('SWOOLEFY_COM_IS_DESTROY') or define('SWOOLEFY_COM_IS_DESTROY', 'is_destroy');
defined('SWOOLEFY_COM_FUNC') or define('SWOOLEFY_COM_FUNC', 'func');

// 定义版本
defined('SWOOLEFY_VERSION') or define('SWOOLEFY_VERSION', '4.0.0');

class MPHP {

	/**
	 * swoolefy框架的版本
	 */
	const VERSION = SWOOLEFY_VERSION;

}