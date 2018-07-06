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

use Swoolefy\Core\Application;
/**
 * dump，调试函数
 * @param    $var
 * @param    $echo
 * @param    $label
 * @param    $strict
 * @return   string            
 */
function dump($var, $echo=true, $label=null, $strict=true) {
    // 判断是否存在访问的应用对象
    $app = Application::getApp();

    $label = ($label === null) ? '' : rtrim($label) . ' ';
    if (!$strict) {
        if (ini_get('html_errors')) {
            $output = print_r($var, true);
            $output = '<pre>' . $label . htmlspecialchars($output, ENT_QUOTES, 'UTF-8') . '</pre>';
        } else {
            $output = $label . print_r($var, true);
        }
    } else {
        ob_start();
        var_dump($var);
        // 获取终端输出
        $output = ob_get_clean();
        if(!extension_loaded('xdebug')) {
            $output = preg_replace('/\]\=\>\n(\s+)/m', '] => ', $output);
            $output = '<pre>' . $label . htmlspecialchars($output, ENT_QUOTES, 'UTF-8') . '</pre>';
        }

        if(is_object($app)) {     
        }else {
          Application::$dump = $output; 
        }
    }
    if($echo) {
        // 调试环境这个函数使用
        if(SW_DEBUG) {
            if(is_object($app)) {
                $app->response->header('Content-Type','text/html; charset=utf-8');
                // worker启动时打印的信息，在下一次请求到来时打印出来
                if(!empty($output)) {
                    $app->response->write($output);
                }
            }  
        }
        return null;
    }else {
        return $output;
    }
        
}

/**
 * _die 异常终端程序执行
 * @param    $msg
 * @param    $code
 * @return   mixed
 */
function _die($msg='') {
    Application::getApp()->response->end();
    throw new \Exception($msg);
}
