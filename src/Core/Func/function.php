<?php
/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | @see https://github.com/bingcool/swoolefy
 * +----------------------------------------------------------------------
 */

use Swoolefy\Core\Application;
use Swoolefy\Core\SystemEnv;

/**
 * dump 调试函数
 * @param $var
 * @param $echo
 * @param $label
 * @param $strict
 * @return string
 */
function dump($var, $echo = true, $label = null, $strict = true)
{
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
        $output = ob_get_contents();
        @ob_end_clean();
        if (!extension_loaded('xdebug')) {
            $output = preg_replace('/\]\=\>\n(\s+)/m', '] => ', $output);
            $output = '<pre>' . $label . htmlspecialchars($output, ENT_QUOTES, 'UTF-8') . '</pre>';
        }
    }
    if ($echo) {
        // 调试环境这个函数使用
        if (!SystemEnv::isPrdEnv()) {
            $app = Application::getApp();
            if (is_object($app)) {
                $app->response->header('Content-Type', 'text/html; charset=utf-8');
                // worker启动时打印的信息，在下一次请求到来时打印出来
                if (!empty($output)) {
                    $app->response->write($output);
                }
            }
        }
        return null;
    } else {
        return $output;
    }

}

/**
 * _die 异常终端程序执行
 * @param $msg
 * @param $code
 * @return mixed
 * @throws \Exception
 */
function _die($msg = '', int $code = 1)
{
    throw new \Exception($msg, $code);
}

/**
 * _each
 * @param $msg
 * @param string $foreground
 * @param string $background
 */
function _each(string $msg, string $foreground = "red", string $background = "black")
{
    // Create new Colors class
    static $colors;
    if (!isset($colors)) {
        $colors = new \Swoolefy\Util\EachColor();
    }
    echo $colors->getColoredString($msg, $foreground, $background);
}

/**
 * 随机获取一个可监听的端口(php_socket模式)
 * @return bool
 */
function get_one_free_port()
{
    $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    if (!socket_bind($socket, "0.0.0.0", 0)) {
        return false;
    }
    if (!socket_listen($socket)) {
        return false;
    }
    if (!socket_getsockname($socket, $addr, $port)) {
        return false;
    }
    socket_close($socket);
    unset($socket);
    return $port;
}

/**
 * 随机获取一个可监听的端口(swoole_coroutine模式)
 * @return mixed
 */
function get_one_free_port_coro()
{
    $socket = new \Swoole\Coroutine\Socket(AF_INET, SOCK_STREAM, IPPROTO_IP);
    $socket->bind('0.0.0.0');
    $socket->listen();
    $port = $socket->getsockname()['port'];
    $socket->close();
    unset($socket);
    return $port;
}
