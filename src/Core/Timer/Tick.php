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

namespace Swoolefy\Core\Timer;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\Coroutine\Context;
use Swoolefy\Core\Table\TableManager;
use Swoolefy\Exception\TimerException;
use Swoolefy\Util\Helper;

class Tick
{
    /**
     * Context key: coroutine-level trace id written on Tick/Cron trigger.
     */
    public const CTX_X_TRACE_ID = 'x-trace-id';

    /**
     * Context key: cron task name written on Cron trigger.
     */
    public const CTX_SYS_CRON_NAME = '_sys_cron_name';

    /**
     * Context key: cron trigger unix timestamp.
     */
    public const CTX_SYS_CRON_TRIGGER_TIME = '_sys_cron_trigger_time';

    /**
     * Context key: tick timer id written on Tick trigger.
     */
    public const CTX_SYS_TICK_TIMER_ID = '_sys_tick_timer_id';

    /**
     * Context key: tick/after trigger unix timestamp.
     */
    public const CTX_SYS_TICK_TRIGGER_TIME = '_sys_tick_trigger_time';

    /**
     * Tick 触发时 x-trace-id 前缀，便于与 After/Cron 区分。
     */
    public const TRACE_ID_PREFIX_TICKTIMER = 'ticktimer_';

    /**
     * After 触发时 x-trace-id 前缀，便于与 Tick/Cron 区分。
     */
    public const TRACE_ID_PREFIX_AFTERTIMER = 'aftertimer_';

    /**
     * $_tick_tasks
     * @var array
     */
    protected static $_tick_tasks = [];

    /**
     * $_after_tasks
     * @var array
     */
    protected static $_after_tasks = [];

    /**
     * $_tasks_instances 任务对象类
     * @var array
     */
    protected static $_tasks_instances = [];

    /**
     * tickTimer
     * @param int $timeIntervalMs
     * @param \Closure|array $func
     * @param array $params
     * @return int
     */
    public static function tickTimer(int $timeIntervalMs, \Closure|array $func, array $params = [])
    {
        if ($timeIntervalMs <= 0) {
            throw new TimerException(get_called_class() . "::tickTimer() the first params 'time_interval_ms' is requested more than 0 ms");
        }

        $timerId = self::tick($timeIntervalMs, $func, $params);

        return $timerId;
    }

    /**
     * tick
     * @param int $timeIntervalMs
     * @param \Closure|array $func
     * @param array $params
     * @return mixed
     */
    protected static function tick(int $timeIntervalMs, \Closure|array $func, array $params = [])
    {
        /**
            注册时不捕获请求 Context，避免长期 Timer 故意不带携带用户身份。Tick/After 是进程级长期定时器，不是请求内的子协程。
            若注册时捕获当前请求 Context，`user` / `tenant` 会被冻住，之后每次触发都带着那次请求的身份，造成跨请求串权。
            当前行为：
            - 注册：不 `snapshot()` 请求 Context
            - 触发：`goApp` 新建干净协程，只写 `_sys_tick_*` 与 `x-trace-id` 系统字段
            - 业务数据：用注册时的 `$params` 显式传入
            这和 `goApp` 不同：`goApp` 跟请求同生共灭，适合短期并发；Tick 会跨大量请求存活，不能背请求身份
        */
        $tid = \Swoole\Timer::tick($timeIntervalMs, function ($timerId, $params) use ($func) {
            goApp(function() use($timerId, $params, $func) {
                // 每次触发仅写入系统字段（含独立 trace id，便于日志排查）
                Context::set(self::CTX_SYS_TICK_TIMER_ID, $timerId);
                Context::set(self::CTX_SYS_TICK_TRIGGER_TIME, time());
                Context::set(self::CTX_X_TRACE_ID, self::TRACE_ID_PREFIX_TICKTIMER . Helper::UUid());
                try {
                    if (is_array($func)) {
                        list($class, $action) = $func;
                        $tickTaskInstance = new $class;
                        $tickTaskInstance->{$action}(...[$params, $timerId]);
                    } else if ($func instanceof \Closure) {
                        call_user_func($func, $params, $timerId);
                    }
                    // call after action
                    if (isset($tickTaskInstance)) {
                        $tickTaskInstance->afterHandle();
                    }
                } catch (\Throwable $throwable) {
                    throw $throwable;
                } finally {
                    if (isset($tickTaskInstance)) {
                        if ($tickTaskInstance->isDefer() === false) {
                            $tickTaskInstance->end();
                        }
                        unset($tickTaskInstance);
                    }
                }
                unset($class, $action, $func);
            });
        }, $params);

        if ($tid) {
            self::$_tick_tasks[$tid] = [
                'callback'      => $func,
                'params'        => $params,
                'time_interval' => $timeIntervalMs,
                'timer_id'      => $tid,
                'start_time'    => date('Y-m-d H:i:s', strtotime('now'))
            ];

            $conf = Swfy::getConf();

            if (isset($conf['enable_table_tick_task']) && !empty($conf['enable_table_tick_task'])) {
                TableManager::set('table_ticker', 'tick_timer_task', ['tick_tasks' => json_encode(self::$_tick_tasks)]);
            }

        }

        return $tid;
    }

    /**
     * delTicker
     * @param int $timerId
     * @return bool
     */
    public static function delTicker(int $timerId): bool
    {
        if (!\Swoole\Timer::exists($timerId)) {
            return true;
        }

        $result = \Swoole\Timer::clear($timerId);

        if ($result) {
            foreach (self::$_tick_tasks as $tid => $value) {
                if ($tid == $timerId) {
                    unset(self::$_tick_tasks[$timerId], self::$_tasks_instances[$timerId]);
                }
            }

            $config = Swfy::getConf();

            if (isset($config['enable_table_tick_task']) && !empty($config['enable_table_tick_task'])) {
                TableManager::set('table_ticker', 'tick_timer_task', ['tick_tasks' => json_encode(self::$_tick_tasks)]);
            }
            return true;
        }

        return false;
    }

    /**
     * afterTimer
     * @param int $timeIntervalMs
     * @param \Closure|array $func
     * @param array $params
     * @return int
     * @throws mixed
     */
    public static function afterTimer(int $timeIntervalMs, \Closure|array $func, array $params = [])
    {
        if ($timeIntervalMs <= 0) {
            throw new TimerException(get_called_class() . "::afterTimer() the first params 'time_interval_ms' is requested more then 0 ms");
        }

        $timerId = self::after($timeIntervalMs, $func, $params);
        return $timerId;
    }

    /**
     * after
     * @param int $timeIntervalMs
     * @param \Closure|array $func
     * @param array $params
     * @return bool|mixed
     */
    protected static function after(int $timeIntervalMs, \Closure|array $func, array $params = [])
    {
        // 注册时不捕获请求 Context，避免 after Timer 隐式复制用户身份
        $timerId = \Swoole\Timer::after($timeIntervalMs, function ($params) use ($func) {
            goApp(function () use($params, $func) {
                // 每次触发仅写入系统字段（含独立 trace id，便于日志排查）
                Context::set(self::CTX_SYS_TICK_TRIGGER_TIME, time());
                Context::set(self::CTX_X_TRACE_ID, self::TRACE_ID_PREFIX_AFTERTIMER . Helper::UUid());
                try {
                    $timer_id = null;
                    if (is_array($func)) {
                        list($class, $action) = $func;
                        $tickTaskInstance = new $class;
                        $tickTaskInstance->{$action}(...[$params, $timer_id]);
                    } else if ($func instanceof \Closure) {
                        call_user_func($func, $params, $timer_id);
                    }
                    // call after action
                    if (isset($tickTaskInstance)) {
                        $tickTaskInstance->afterHandle();
                    }
                } catch (\Throwable $throwable) {
                    throw $throwable;
                } finally {
                    if (isset($tickTaskInstance)) {
                        if ($tickTaskInstance->isDefer() === false) {
                            $tickTaskInstance->end();
                        }
                        unset($tickTaskInstance);
                    }
                }

                self::updateRunAfterTick();
                unset($class, $action, $func);
            });
        }, $params);

        if ($timerId) {
            self::$_after_tasks[$timerId] = [
                'callback'      => $func,
                'params'        => $params,
                'time_interval' => $timeIntervalMs,
                'timer_id'      => $timerId,
                'start_time'    => date('Y-m-d H:i:s', strtotime('now'))
            ];

            $conf = Swfy::getConf();
            if (isset($conf['enable_table_tick_task']) && !empty($conf['enable_table_tick_task'])) {
                TableManager::set('table_after', 'after_timer_task', ['after_tasks' => json_encode(self::$_after_tasks)]);
            }
        }

        return $timerId;
    }

    /**
     * updateRunAfterTick
     * @return void
     */
    protected static function updateRunAfterTick()
    {
        if (self::$_after_tasks) {
            $now = strtotime('now') * 1000 + 1000;

            foreach (self::$_after_tasks as $key => $value) {
                $end_time = $value['time_interval'] + strtotime($value['start_time']) * 1000;
                if ($now >= $end_time) {
                    unset(self::$_after_tasks[$key]);
                }
            }

            $conf = Swfy::getConf();
            if (isset($conf['enable_table_tick_task']) && !empty($conf['enable_table_tick_task'])) {
                TableManager::set('table_after', 'after_timer_task', ['after_tasks' => json_encode(self::$_after_tasks)]);
            }
        }
    }

}