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

use Swoole\Timer;
use Swoolefy\Core\Swfy;
use Swoolefy\Core\Table\TableManager;

class TickManager
{

    use \Swoolefy\Core\SingletonTrait;

    /**
     * tickTimer 循环定时器
     * @param int $time_interval_ms
     * @param mixed $func
     * @param mixed $params
     * @return  int
     */
    public static function tickTimer(int $time_interval_ms, $func, $params = null)
    {
        return Tick::tickTimer($time_interval_ms, $func, $params);
    }

    /**
     * afterTimer 一次性定时器
     * @param int $time_interval_ms
     * @param mixed $func
     * @param mixed $params
     * @return int
     */
    public static function afterTimer(int $time_interval_ms, $func, $params = null)
    {
        return Tick::afterTimer($time_interval_ms, $func, $params);
    }

    /**
     * clearTimer
     * @param int $timer_id
     * @return boolean
     */
    public static function clearTimer(int $timer_id)
    {
        if (is_int($timer_id)) {
            return Tick::delTicker($timer_id);
        }
    }

    /**
     * getTickTasks 获取实时在线的循环定时任务
     * @return  mixed
     */
    public static function getTickTasks()
    {
        $config = Swfy::getConf();
        if (isset($config['enable_table_tick_task'])) {
            $is_enable_table_tick_task = filter_var($config['enable_table_tick_task'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($is_enable_table_tick_task) {
                return json_decode(TableManager::get('table_ticker', 'tick_timer_task', 'tick_tasks'), true);
            }
        }
        return [];
    }

    /**
     * getAfterTasks 获取实时的在线一次性定时任务
     * @return  mixed
     */
    public static function getAfterTasks()
    {
        $config = Swfy::getConf();
        if (isset($config['enable_table_tick_task'])) {
            $is_enable_table_tick_task = filter_var($config['enable_table_tick_task'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($is_enable_table_tick_task) {
                return json_decode(TableManager::get('table_after', 'after_timer_task', 'after_tasks'), true);
            }
        }
        return [];
    }

    /**
     * @param int $fd
     */
    public static function timerInfo(int $fd)
    {
        return Timer::info($fd);
    }

    /**
     * @return mixed
     */
    public static function timerList()
    {
        return Timer::list();
    }

    /**
     * @return mixed
     */
    public static function timerStatus()
    {
        return Timer::stats();
    }

    /**
     * __callStatic
     * @param string $name
     * @param mixed $args
     * @return mixed
     */
    public static function __callStatic(string $name, $args)
    {
        return false;
    }

}