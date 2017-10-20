<?php

/**
 * dump，调试函数
 * @param    $var
 * @param    $echo
 * @param    $label
 * @param    $strict
 * @return   string            
 */
function dump($var, $echo=true, $label=null, $strict=true) {
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
        if (!extension_loaded('xdebug')) {
            $output = preg_replace('/\]\=\>\n(\s+)/m', '] => ', $output);
            $output = '<pre>' . $label . htmlspecialchars($output, ENT_QUOTES, 'UTF-8') . '</pre>';
        }
    }
    if($echo) {
    	// 调试环境这个函数使用
        if(SW_DEBUG) {
            $response = \Swoolefy\Core\Application::$app->response;
            $response->header('Content-Type','text/html; charset=utf-8');
            $response->write($output);
        }
        return null;
    }else
        return $output;
}

function get_used_memory() {
   return memory_get_usage();
}