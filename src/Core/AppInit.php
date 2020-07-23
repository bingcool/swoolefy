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

use Swoole\Http\Request;

class AppInit extends AppObject {
	/**
	 * @param Request $request
	 */
	public static function init($request) {
		// 重置SERVER请求对象
		foreach($request->server as $p=>$val) {
			$upper = strtoupper($p);
			$request->server[$upper] = $val;
			unset($request->server[$p]);
		}
		foreach($request->header as $key => $value) {
            $_key = 'HTTP_' . strtoupper(str_replace('-', '_', $key));
            $request->server[$_key] = $value;
            $request->header[$_key] = $value;
        }
	}
}
