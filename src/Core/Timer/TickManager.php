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
     * tickTimer
     * @param int $time_interval_ms
     * @param mixed $func
     * @param mixed $params
     * @return int
     */
    public static function tickTimer(int $time_interval_ms, $func, $params = null)
    {
        return Tick::tickTimer($time_interval_ms, $func, $params);
    }

    /**
     * afterTimer
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
     * @return bool
     */
    public static function clearTimer(int $timer_id)
    {
        if (is_int($timer_id)) {
            return Tick::delTicker($timer_id);
        }
    }

    /**
     * getTickTasks
     * @return mixed
     */
    public static function getTickTasks()
    {
        $conf = Swfy::getConf();
        if (isset($conf['enable_table_tick_task'])) {
            $isEnableTableTickTask = filter_var($conf['enable_table_tick_task'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($isEnableTableTickTask) {
                return json_decode(TableManager::get('table_ticker', 'tick_timer_task', 'tick_tasks'), true);
            }
        }
        return [];
    }

    /**
     * getAfterTasks
     * @return array
     */
    public static function getAfterTasks()
    {
        $conf = Swfy::getConf();
        if (isset($conf['enable_table_tick_task'])) {
            $isEnableTableTickTask = filter_var($conf['enable_table_tick_task'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($isEnableTableTickTask) {
                return json_decode(TableManager::get('table_after', 'after_timer_task', 'after_tasks'), true);
            }
        }
        return [];
    }

    /**
     * @param int $fd
     * @return array
     */
    public static function timerInfo(int $fd)
    {
        return Timer::info($fd);
    }

    /**
     * @return \Swoole\timer\Iterator
     */
    public static function timerList()
    {
        return Timer::list();
    }

    /**
     * @return array
     */
    public static function timerStatus()
    {
        return Timer::stats();
    }

    /**
     * __callStatic
     * @param string $name
     * @param array $args
     * @return mixed
     */
    public static function __callStatic(string $name, array $args)
    {
        return false;
    }

}