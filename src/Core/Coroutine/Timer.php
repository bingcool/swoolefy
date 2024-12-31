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

use Swoole\Coroutine\Channel;
use Swoolefy\Core\Application;

class Timer
{
    /**
     * @param int $timeMs
     * @param callable $callable
     * @param bool $withBlockLapping 是否每个时间轮任务都执行，不管上个定时任务是否已执行完毕.默认false,允许重叠执行
     * $withBlockLapping=true 将不会重叠执行，必须等上一个任务执行完毕，下一轮时间到了,也不会执行，必须等到上一轮任务结束后，再接着执行，即所谓的阻塞执行
     * $withBlockLapping=false 允许任务重叠执行，不管上一个任务的是否执行完毕，下一轮时间到了，任务将在一个新的协程中执行。默认false
     * @return Channel
     */
    public static function tick(int $timeMs, callable $callable, bool $withBlockLapping = false)
    {
        $timeChannel = new Channel(1);
        $second  = round($timeMs / 1000, 3);
        if ($second < 0.001) {
            $second = 0.001;
        }

        goApp(function ($second, $callable) use ($timeChannel, $withBlockLapping) {
            while (true) {
                $value = $timeChannel->pop($second);
                if($value !== false) {
                    $timeChannel->close();
                    break;
                }

                // block
                if ($withBlockLapping) {
                    try {
                        $callable($timeChannel);
                    }catch (\Throwable $throwable) {
                        \Swoolefy\Core\BaseServer::catchException($throwable);
                    } finally {
                        $App = Application::getApp();
                        if (is_object($App)) {
                            Application::getApp()->clearComponent();
                        }
                    }
                }else {
                    // no block
                    goApp(function () use($timeChannel, $callable) {
                        $callable($timeChannel);
                    });
                }

            }
        }, $second, $callable);

        return $timeChannel;
    }

    /**
     * cancel tick timer
     *
     * @param Channel|int $timeChannel
     * @return bool
     */
    public static function cancel($timeChannel): bool
    {
        if ($timeChannel instanceof Channel) {
            return $timeChannel->push(1);
        }else if (is_int($timeChannel)) {
            $timerId = $timeChannel;
            if(\Swoole\Timer::exists($timerId)) {
                \Swoole\Timer::clear($timerId);
            }
            return true;
        }

        return false;
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

        goApp(function ($second, $callable) use ($timeChannel) {
            while (!$timeChannel->pop($second)) {
                goApp(function () use($timeChannel, $callable) {
                    $callable($timeChannel);
                });
                break;
            }
        }, $second, $callable);

        return $timeChannel;
    }

}