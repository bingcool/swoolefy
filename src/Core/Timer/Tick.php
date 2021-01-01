<?php
/**
+----------------------------------------------------------------------
| swoolefy framework bases on swoole extension development, we can use it easily!
+----------------------------------------------------------------------
| Licensed ( https://opensource.org/licenses/MIT )
+----------------------------------------------------------------------
| @see https://github.com/bingcool/swoolefy
+----------------------------------------------------------------------
*/

namespace Swoolefy\Core\Timer;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\Application;
use Swoolefy\Core\BaseServer;
use Swoolefy\Core\Table\TableManager;

class Tick {
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
     * tickTimer  循环定时器
     * @param   int      $time_interval_ms
     * @param   callable $func         
     * @param   array    $params
     * @throws  mixed
     * @return  mixed
     */
	public static function tickTimer(int $time_interval_ms, callable $func, $params = null) {
		if($time_interval_ms <= 0) {
            throw new \Exception(get_called_class()."::tickTimer() the first params 'time_interval' is requested more than 0 ms");
        }

        $timer_id = self::tick($time_interval_ms, $func, $params);

        return $timer_id;
	}

    /**
     * tick  循环定时器执行
     * @param   int       $time_interval_ms
     * @param   callable  $func         
     * @param   array     $params
     * @return  mixed
     */
    public static function tick(int $time_interval_ms, callable $func, $params = null) {
        $tid = \Swoole\Timer::tick($time_interval_ms, function($timer_id, $params) use($func) {
            try {
                if(is_array($func)) {
                    list($class, $action) = $func;
                    $tickTaskInstance = new $class;
                    $tickTaskInstance->{$action}(...[$params, $timer_id]);
                }else if($func instanceof \Closure) {
                    $tickTaskInstance = new TickController();
                    call_user_func($func, $params, $timer_id);
                }
            }catch(\Throwable $t) {
                BaseServer::catchException($t);
            }finally {
                if($tickTaskInstance->isDefer() === false) {
                    $tickTaskInstance->end();
                }
                if(method_exists("Swoolefy\\Core\\Application", 'removeApp')) {
                    if(is_object($tickTaskInstance)) {
                        Application::removeApp($tickTaskInstance->coroutine_id);
                    }
                }
            }
            unset($tickTaskInstance, $class, $action, $user_params, $func);
        }, $params);

        if($tid) {
            self::$_tick_tasks[$tid] = [
                'callback'=>$func,
                'params'=>$params,
                'time_interval'=>$time_interval_ms,
                'timer_id'=>$tid,
                'start_time'=>date('Y-m-d H:i:s', strtotime('now'))
            ];
            $config = Swfy::getConf();
            if(isset($config['enable_table_tick_task']) && $config['enable_table_tick_task'] == true) {
                TableManager::set('table_ticker', 'tick_timer_task', ['tick_tasks'=>json_encode(self::$_tick_tasks)]);
            }
            
        }

        return $tid ? $tid : false;
    }

    /**
     * delTicker 删除循环定时器
     * @param    int  $timer_id
     * @return   boolean         
     */
    public static function delTicker(int $timer_id) {
        if(!\Swoole\Timer::exists($timer_id)) {
            return true;
        }
        $result = \Swoole\Timer::clear($timer_id);
        if($result) {
            foreach(self::$_tick_tasks as $tid=>$value) {
                if($tid == $timer_id) {
                    unset(self::$_tick_tasks[$timer_id], self::$_tasks_instances[$timer_id]); 
                }
            }
            $config = Swfy::getConf();
            if(isset($config['enable_table_tick_task']) && $config['enable_table_tick_task'] == true) {
                TableManager::set('table_ticker', 'tick_timer_task', ['tick_tasks'=>json_encode(self::$_tick_tasks)]);
                return true;
            }
        }

        return false;
    }

    /**
     * afterTimer 一次性定时器
     * @param    int       $time_interval_ms
     * @param    callable  $func         
     * @param    array     $params
     * @throws   mixed
     * @return   mixed
     */
    public static function afterTimer(int $time_interval_ms, callable $func, $params = null) {
        if($time_interval_ms <= 0) {
            throw new \Exception(get_called_class()."::afterTimer() the first params 'time_interval' is requested more then 0 ms");
        }

        $timer_id = self::after($time_interval_ms, $func, $params);
        return $timer_id;
    }

    /**
     * after 一次性定时器执行
     * @return  mixed
     */
    public static function after(int $time_interval_ms, callable $func, $params = null) {
        $tid = \Swoole\Timer::after($time_interval_ms, function($params) use($func) {
            try{
                $timer_id = null;
                if(is_array($func)) {
                    list($class, $action) = $func;
                    $tickTaskInstance = new $class;
                    $tickTaskInstance->{$action}(...[$params, $timer_id]);
                }else if($func instanceof \Closure) {
                    $tickTaskInstance = new TickController;
                    call_user_func($func, $params, $timer_id);
                }
            }catch (\Throwable $t) {
                BaseServer::catchException($t);
            }finally {
                if($tickTaskInstance->isDefer() === false) {
                    $tickTaskInstance->end();
                }
                if(method_exists("Swoolefy\\Core\\Application", 'removeApp')) {
                    if(is_object($tickTaskInstance)) {
                        Application::removeApp($tickTaskInstance->coroutine_id);
                    }
                }
            }
            // 执行完之后,更新目前的一次性任务项
            self::updateRunAfterTick();
            unset($tickTaskInstance, $class, $action, $user_params, $func);
        }, $params);

        if($tid) {
            self::$_after_tasks[$tid] = [
                'callback'=>$func,
                'params'=>$params,
                'time_interval'=>$time_interval_ms,
                'timer_id'=>$tid,
                'start_time'=>date('Y-m-d H:i:s',strtotime('now'))
            ];
            $config = Swfy::getConf();
            if(isset($config['enable_table_tick_task']) && $config['enable_table_tick_task'] == true) {
                TableManager::set('table_after', 'after_timer_task', ['after_tasks'=>json_encode(self::$_after_tasks)]);
            }
        }

        return $tid ? $tid : false;
    }

    /**
     * updateRunAfterTick 更新一次定时器
     * @return  void
     */
    protected static function updateRunAfterTick() {
        if(self::$_after_tasks) {
            // 加上1000,提前1s
            $now = strtotime('now') * 1000 + 1000;
            foreach(self::$_after_tasks as $key=>$value) {
                $end_time = $value['time_interval'] + strtotime($value['start_time']) * 1000;
                if($now >= $end_time) {
                    unset(self::$_after_tasks[$key]);    
                }
            }
            $config = Swfy::getConf();
            if(isset($config['enable_table_tick_task']) && $config['enable_table_tick_task'] == true) {
                TableManager::set('table_after', 'after_timer_task', ['after_tasks'=>json_encode(self::$_after_tasks)]);
            }
        }
    }

}