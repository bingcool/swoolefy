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

use Swoole\Coroutine\Channel;

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
        try {
            if (!socket_bind($socket, "0.0.0.0", 0)) {
                throw new \Swoolefy\Exception\SystemException("get_one_free_port call socket_bind() failed");
            }
            if (!socket_listen($socket)) {
                throw new \Swoolefy\Exception\SystemException("get_one_free_port call socket_listen() failed");
            }
            if (!socket_getsockname($socket, $addr, $port)) {
                throw new \Swoolefy\Exception\SystemException("get_one_free_port call socket_getsockname() failed");
            }
        }catch (\Throwable $exception) {
            throw $exception;
        }finally {
            socket_close($socket);
        }

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

function fmtPrintInfo($msg, bool $newLine = true)
{
    if (is_array($msg)) {
        initConsoleStyleIo()->definitionList($msg);
    }else {
        initConsoleStyleIo()->info($msg);
    }

    if ($newLine) {
        initConsoleStyleIo()->newLine();
    }
}

function fmtPrintError($msg, bool $newLine = true)
{
    if (is_array($msg)) {
        initConsoleStyleIo()->definitionList($msg);
    }else {
        initConsoleStyleIo()->error($msg);
    }

    if ($newLine) {
        initConsoleStyleIo()->newLine();
    }
}

function initConsoleStyleIo()
{
    static $consoleStyleIo;
    if (!isset($consoleStyleIo)) {
        $input = new \Symfony\Component\Console\Input\ArgvInput();
        $output = new \Symfony\Component\Console\Output\ConsoleOutput();
        $consoleStyleIo = new \Symfony\Component\Console\Style\SymfonyStyle($input, $output);
    }
    return $consoleStyleIo;
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
 * @param $appName
 * @return void
 */
function resisterNamespace($appName)
{
    $file = $appName.'/autoloader.php';
    if (file_exists($file)) {
        include $file;
    }
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
    $contextData = \Swoolefy\Core\Coroutine\Context::getContext()->getArrayCopy();
    return \Swoole\Coroutine::create(function () use($callback, $params, $contextData) {
        foreach ($contextData as $key=>$value) {
            \Swoolefy\Core\Coroutine\Context::set($key, $value);
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

/**
 * @param int $timeMs
 * @param callable $callable
 * @param bool $withBlockLapping 是否每个时间任务都执行，不管上个定时任务是否一致性完毕。
 * $withBlockLapping=true 将不会重叠执行，必须等上一个任务执行完毕，下一轮时间到了,也不会执行，必须等到上一轮任务结束后，再接着执行
 * $withBlockLapping=false 允许任务重叠执行，不管上一个任务的是否执行完毕，下一轮时间到了，任务将在一个新的协程中执行。默认false
 * @return Channel|int
 */
function goTick(int $timeMs, callable $callable, bool $withBlockLapping = false)
{
    if (\Swoole\Coroutine::getCid() >= 0) {
        return \Swoolefy\Core\Coroutine\Timer::tick($timeMs, $callable, $withBlockLapping);
    }else {
        return \Swoole\Timer::tick($timeMs, function () use($callable) {
            (new \Swoolefy\Core\EventApp)->registerApp(function() use($callable) {
                try {
                    $callable();
                }catch (\Throwable $throwable) {
                    \Swoolefy\Core\BaseServer::catchException($throwable);
                }
            });
        });
    }
}

/**
 * @param int $timeMs
 * @param callable $callable
 * @return Channel|int
 */
function goAfter(int $timeMs, callable $callable)
{
    if (\Swoole\Coroutine::getCid() >= 0) {
        return \Swoolefy\Core\Coroutine\Timer::after($timeMs, $callable);
    }else {
        return \Swoole\Timer::after($timeMs, function () use($callable) {
            (new \Swoolefy\Core\EventApp)->registerApp(function() use($callable) {
                try {
                    $callable();
                }catch (\Throwable $throwable) {
                    \Swoolefy\Core\BaseServer::catchException($throwable);
                }
            });
        });
    }
}
