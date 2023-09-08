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

/**
 * @param string $appName
 * @return string
 */
function makeServerName(string $appName)
{
    if (IS_DAEMON_SERVICE == 1 && IS_CRON_SERVICE == 0 && IS_CLI_SCRIPT == 0 ) {
        return strtolower($appName.'-'.'daemon');
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
    $arrayCopy = \Swoolefy\Core\Coroutine\Context::getContext()->getArrayCopy();
    return \Swoole\Coroutine::create(function () use($callback, $params, $arrayCopy) {
        foreach ($arrayCopy as $key=>$value) {
            Swoolefy\Core\Coroutine\Context::set($key, $value);
        }
        (new \Swoolefy\Core\EventApp)->registerApp(function($event) use($callback, $params) {
            try {
                array_push($params, $event);
                $callback(...$params);
            }catch (\Throwable $throwable) {
                \Swoolefy\Core\BaseServer::catchException($throwable);
            }
        });
    });
}
