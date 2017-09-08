<?php
namespace Swoolefy\Core\Timer;

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
     * tickTimer
     * @param    $time_interval
     * @param    $func         
     * @param    $params       
     * @return   int              
     */
	public static function tickTimer($time_interval, $func, $params=[]) {
		if($time_interval <= 0) {
            throw new \Exception("time_interval is less 0");
            return false;
        }

        if(!is_callable($func)) {
            throw new \Exception("not callable");
            return false;
        }

        $timer_id = self::tick($time_interval,$func,$params);

        return $timer_id;
	}

    /**
     * tick
     * @param    $time_interval
     * @param    $func         
     * @param    $user_params  
     * @return   boolean              
     */
    public static function tick($time_interval,$func,$user_params=[]) {
        $tid = swoole_timer_tick($time_interval, function($timer_id,$user_params) use($func) {
            array_push($user_params,$timer_id);
            call_user_func_array($func, $user_params);
        },$user_params);

        if($tid) {
            self::$_tick_tasks[$tid] = array('callback'=>$func, 'params'=>$user_params, 'time_interval'=>$time_interval, 'timer_id'=>$tid, 'start_time'=>date('Y-m-d H:i:s',strtotime('now')));
        }

        return $tid ? $tid : false;
    }

    /**
     * getRuningTask
     * @return   array
     */
    public static function getRunTickTask() {
        return self::$_tick_tasks;
    }

    /**
     * delTicker
     * @param    $timer_id
     * @return   boolean         
     */
    public static function delTicker($timer_id) {
        $result = swoole_timer_clear($timer_id);
        if($result) {
            foreach(self::$_tick_tasks as $tid=>$value) {
                if($tid == $timer_id) {
                    unset(self::$_tick_tasks['$tid']);
                }
            }

            return true;
        }

        return false;
    }

    /**
     * afterTimer
     * @param    $time_interval
     * @param    $func         
     * @param    $params       
     * @return   int              
     */
    public static function afterTimer($time_interval, $func, $params=[]) {
        if($time_interval <= 0) {
            throw new \Exception("time_interval is less 0");
            return false;
        }

        if(!is_callable($func)) {
            throw new \Exception("not callable");
            return false;
        }

        $timer_id = self::after($time_interval,$func,$params);

        return $timer_id;
    }

    /**
     * after
     * @return  boolean
     */
    public static function after($time_interval,$func,$params=[]) {
        $tid = swoole_timer_after($time_interval, function($timer_id,$user_params) use($func) {
            array_push($user_params,$timer_id);
            call_user_func_array($func, $user_params);
        },$user_params);

        if($tid) {
            self::$_after_tasks[$tid] = array('callback'=>$func, 'params'=>$user_params, 'time_interval'=>$time_interval, 'timer_id'=>$tid, 'start_time'=>date('Y-m-d H:i:s',strtotime('now')));
        }

        return $tid ? $tid : false;
    }

    /**
     * getRunAfterTick
     * @return  array
     */
    public static function getRunAfterTick() {
        if(self::$_after_tasks) {
            $now = strtotime('now') * 1000;
            foreach(self::$_after_tasks as $key=>$value) {
                $end_time = $value['time_interval'] + strtotime($value['start_time']) * 1000;
                if($now > $end_time) {
                    unset(self::$_after_tasks[$key]);
                }
            }
        }

        return self::$_after_tasks;
    }


}