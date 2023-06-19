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
 * dump debug method
 * @param $var
 * @param $echo
 * @param $label
 * @param $strict
 * @return string
 */
function mydump($var, $echo = true, $label = null, $strict = true)
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
            if (is_object($app) && isset($app->response)) {
                $app->response->header('Content-Type', 'text/html; charset=utf-8');
                // worker启动时打印的信息，在下一次请求到来时打印出来
                if (!empty($output)) {
                    $app->response->write($output);
                }
            }else {
                var_dump($var);
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
 *
 * @param array $excludePorts 排除的端口
 * @return int
 */
function get_one_free_port(array $excludePorts = []): int
{
    $isValidPort = true;
    do {
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

        if(empty($excludePorts)) {
            $isValidPort = false;
        }else {
            if(!in_array($port, $excludePorts)) {
                $isValidPort = false;
            }
        }
        unset($socket);
    }while($isValidPort);

    return $port;
}
/**
 * 随机获取一个可监听的端口(php_socket模式)
 *
 * @param array $excludePorts 排除的端口
 * @return int
 */
function getOneFreePort(array $excludePorts = []): int
{
    return get_one_free_port($excludePorts);
}

function makeServerName(string $appName)
{
    if (IS_DAEMON_SERVICE == 1 && IS_CRON_SERVICE == 0 && IS_CLI_SCRIPT == 0 ) {
        return strtolower($appName.'-'.'worker');
    }

    if (IS_CRON_SERVICE == 1) {
        return strtolower($appName.'-'.'cron');
    }

    return strtolower($appName.'-'.'script');
}

/**
 * 协程单例
 *
 * @param \Closure|callable $callback
 * @param ...$params
 * @return int|false
 * @throws \Swoolefy\Exception\SystemException
 */
function goApp(callable $callback, ...$params) {
    return \Swoole\Coroutine::create(function () use($callback, $params) {
        (new \Swoolefy\Core\EventApp)->registerApp(function($event) use($callback, $params) {
            try {
                array_push($params, $event);
                $callback(...$params);
            }catch (\Throwable $throwable) {
                \Swoolefy\Core\BaseServer::catchException($throwable);
            } finally {
                // do not thing
            }
        });
    });
}
