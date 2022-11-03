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

namespace Swoolefy\Core\Coroutine;

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoolefy\Core\BaseServer;

class Timer
{
    /**
     * @param int $timeMs
     * @param callable $callable
     * @return Channel
     */
    public static function tick(int $timeMs, callable $callable)
    {
        $timeChannel = new Channel(1);
        $second  = round($timeMs / 1000, 3);
        if ($second < 0.001) {
            $second = 0.001;
        }

        Coroutine::create(function ($second, $callable) use ($timeChannel) {
            while (true) {
                $value = $timeChannel->pop($second);
                if($value !== false) {
                    $timeChannel->close();
                    break;
                }
                try {
                    Coroutine::create(function ($callable) use($timeChannel) {
                        try {
                            $callable($timeChannel);
                        }catch (\Throwable $exception) {
                            BaseServer::catchException($exception);
                        }
                    }, $callable);
                }catch (\Throwable $exception)
                {
                    BaseServer::catchException($exception);
                }
            }
        }, $second, $callable);

        return $timeChannel;
    }

    /**
     * cancel tick timer
     *
     * @param Channel $channel
     * @return bool
     */
    public static function cancel(Channel $channel): bool
    {
        return $channel->push(1);
    }

    /**
     * @param int $timeMs
     * @param callable $callable
     * @return Channel
     */
    public static function after(int $timeMs, callable $callable)
    {
        $timeChannel = new Channel(1);
        $second  = round($timeMs / 1000, 3);
        if ($second < 0.001) {
            $second = 0.001;
        }

        Coroutine::create(function ($second, $callable) use ($timeChannel) {
            while (!$timeChannel->pop($second)) {
                Coroutine::create(function ($callable) use($timeChannel) {
                    try {
                        $callable($timeChannel);
                    }catch (\Throwable $exception) {
                        BaseServer::catchException($exception);
                    }
                }, $callable);
                break;
            }
        }, $second, $callable);

        return $timeChannel;
    }

}