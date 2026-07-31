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
use Swoolefy\Core\Timer\Tick;
use Swoolefy\Util\Helper;

class Timer
{
    /**
     * @param int $timeMs 单位：毫秒
     * @param callable $callable
     * @param bool $withBlockLapping 是否每个时间轮任务都执行，不管上个定时任务是否已执行完毕.默认false,允许重叠执行
     * $withBlockLapping=true 阻塞执行,将不会重叠执行，必须等上一个任务执行完毕，下一轮时间到了,也不会执行，必须等到上一轮任务结束后，再接着执行，即所谓的阻塞执行
     * $withBlockLapping=false 允许任务重叠执行，不管上一个任务的是否执行完毕，下一轮时间到了，任务将在一个新的协程中执行。默认false
     * @return Channel
     */
    public static function tick(int $timeMs, callable $callable, bool $withBlockLapping = false): Channel
    {
        $timeChannel = new Channel(1);
        $timeSecond = round($timeMs / 1000, 3);
        if ($timeSecond < 0.01) {
            $timeSecond = 0.01;
        }

        goApp(function ($timeSecond, $callable) use ($timeChannel, $withBlockLapping) {
            while (true) {
                $value = $timeChannel->pop($timeSecond);
                if($value !== false) {
                    $timeChannel->close();
                    break;
                }
                // 每次触发仅写入系统字段（含独立 trace id，便于日志排查）
                Context::set(Tick::CTX_SYS_TICK_TIMER_ID, 'channel');
                Context::set(Tick::CTX_SYS_TICK_TRIGGER_TIME, time());
                Context::set(Tick::CTX_X_TRACE_ID, Tick::TRACE_ID_PREFIX_TICKTIMER . Helper::UUid());
                // block
                if ($withBlockLapping) {
                    try {
                        $callable($timeChannel);
                    } catch (\Throwable $throwable) {
                        \Swoolefy\Core\BaseServer::catchException($throwable);
                    } finally {
                        $App = Application::getApp();
                        if (is_object($App)) {
                            Application::getApp()->clearComponent();
                        }
                    }
                } else {
                    // no block
                    goApp(function () use($timeChannel, $callable) {
                        $callable($timeChannel);
                    });
                }

            }
        }, $timeSecond, $callable);

        return $timeChannel;
    }

    /**
     * cancel tick timer
     *
     * @param Channel|int $timeChannel
     * @return bool
     */
    public static function cancel(Channel|int $timeChannel): bool
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
     * @param int $timeMs 单位：毫秒
     * @param callable $callable
     * @return Channel
     */
    public static function after(int $timeMs, callable $callable): Channel
    {
        $timeChannel = new Channel(1);
        $timeSecond  = round($timeMs / 1000, 3);
        if ($timeSecond < 0.01) {
            $timeSecond = 0.01;
        }

        goApp(function ($timeSecond, $callable) use ($timeChannel) {
            while (!$timeChannel->pop($timeSecond)) {
                // 每次触发仅写入系统字段（含独立 trace id，便于日志排查）
                Context::set(Tick::CTX_SYS_TICK_TRIGGER_TIME, time());
                Context::set(Tick::CTX_X_TRACE_ID, Tick::TRACE_ID_PREFIX_AFTERTIMER . Helper::UUid());
                goApp(function () use($timeChannel, $callable) {
                    $callable($timeChannel);
                });
                break;
            }
        }, $timeSecond, $callable);

        return $timeChannel;
    }

}