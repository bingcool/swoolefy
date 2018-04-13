<?php
namespace Swoolefy\Core\Timer;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\Timer\Tick;
use Swoolefy\Core\Table\TableManager;

class TickManager {

	use \Swoolefy\Core\SingleTrait;

	/**
	 * tickTimer 循环定时器
	 * @param   int    $time_interval
	 * @param   mixed  $func         
	 * @param   mixed  $params       
	 * @return  int
	 */
	public static function tickTimer($time_interval, $func, $params = null) {
		return Tick::tickTimer($time_interval, $func, $params);
	}

	/**
	 * afterTimer 一次性定时器
	 * @param  int   $time_interval
	 * @param  mixed $func         
	 * @param  mixed $params       
	 * @return int
	 */
	public static function afterTimer($time_interval, $func, $params = null) {
		return Tick::afterTimer($time_interval, $func, $params);
	}

	/**
	 * clearTimer 
	 * @param  int    $timer_id
	 * @return boolean
	 */
	public static function clearTimer(int $timer_id) {
		if(is_int($timer_id)) {
			return Tick::delTicker($timer_id);
		}
		
	}

	/**
	 * getTickTasks 获取实时在线的循环定时任务
	 * @return   mixed
	 */
	public static function getTickTasks() {
		if(isset(Swfy::$config['table_tick_task']) && Swfy::$config['table_tick_task'] == true) {
			return json_decode(TableManager::get('table_ticker', 'tick_timer_task', 'tick_tasks'), true);
		}
		return false;								
	}

	/**
	 * getAfterTasks 获取实时的在线一次性定时任务
	 * @return   mixed
	 */
	public static function getAfterTasks() {
		if(isset(Swfy::$config['table_tick_task']) && Swfy::$config['table_tick_task'] == true) {
			return json_decode(TableManager::get('table_after', 'after_timer_task', 'after_tasks'), true);
		}
		return false;
	}

	/**
	 * __callStatic 
	 * @param  string $name
	 * @param  mixed  $args
	 * @return mixed      
	 */
	public static function __callStatic($name, $args) {
		return false;
	}

}