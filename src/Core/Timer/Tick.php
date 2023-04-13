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
use Swoolefy\Core\Application;
use Swoolefy\Core\BaseServer;
use Swoolefy\Core\Table\TableManager;
use Swoolefy\Exception\TimerException;

class Tick
{
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
        $tid = \Swoole\Timer::tick($timeIntervalMs, function ($timerId, $params) use ($func) {
            try {
                if (is_array($func)) {
                    list($class, $action) = $func;
                    $tickTaskInstance = new $class;
                    $tickTaskInstance->{$action}(...[$params, $timerId]);
                } else if ($func instanceof \Closure) {
                    $tickTaskInstance = new TickController();
                    call_user_func($func, $params, $timerId);
                }
                // call after action
                $tickTaskInstance->afterHandle();
            } catch (\Throwable $throwable) {
                BaseServer::catchException($throwable);
            } finally {
                if (isset($tickTaskInstance)) {
                    if ($tickTaskInstance->isDefer() === false) {
                        $tickTaskInstance->end();
                    }

                    if (is_object($tickTaskInstance)) {
                        Application::removeApp($tickTaskInstance->getCid());
                    }
                    unset($tickTaskInstance);
                }
            }
            unset($class, $action, $func);

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

            if (isset($conf['enable_table_tick_task']) && $conf['enable_table_tick_task'] == true) {
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

            if (isset($config['enable_table_tick_task']) && $config['enable_table_tick_task'] == true) {
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
        $timerId = \Swoole\Timer::after($timeIntervalMs, function ($params) use ($func) {
            try {
                $timer_id = null;
                if (is_array($func)) {
                    list($class, $action) = $func;
                    $tickTaskInstance = new $class;
                    $tickTaskInstance->{$action}(...[$params, $timer_id]);
                } else if ($func instanceof \Closure) {
                    $tickTaskInstance = new TickController;
                    call_user_func($func, $params, $timer_id);
                }
                // call after action
                $tickTaskInstance->afterHandle();
            } catch (\Throwable $throwable) {
                BaseServer::catchException($throwable);
            } finally {
                if (isset($tickTaskInstance)) {
                    if ($tickTaskInstance->isDefer() === false) {
                        $tickTaskInstance->end();
                    }

                    if (is_object($tickTaskInstance)) {
                        Application::removeApp($tickTaskInstance->getCid());
                    }
                    unset($tickTaskInstance);
                }
            }

            self::updateRunAfterTick();
            unset($class, $action, $func);
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
            if (isset($conf['enable_table_tick_task']) && $conf['enable_table_tick_task'] == true) {
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
            if (isset($conf['enable_table_tick_task']) && $conf['enable_table_tick_task'] == true) {
                TableManager::set('table_after', 'after_timer_task', ['after_tasks' => json_encode(self::$_after_tasks)]);
            }
        }
    }

}