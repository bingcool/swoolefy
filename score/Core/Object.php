<?php
namespace Swoolefy\Core;

use Swoolefy\Core\Swfy;

class Object {

	/**
	 * getTickTasks 获取实时在线的循环定时任务
	 * @return   mixed
	 */
	public static function getTickTasks() {
		if(isset(Swfy::$config['table_tick_task']) && Swfy::$config['table_tick_task'] == true) {
			return json_decode(Swfy::$server->table_ticker->get('tick_timer_task','tick_tasks'),true);
		}
		return false;								
	}

	/**
	 * getAfterTasks 获取实时的在线一次性定时任务
	 * @return   mixed
	 */
	public static function getAfterTasks() {
		if(isset(Swfy::$config['table_tick_task']) && Swfy::$config['table_tick_task'] == true) {
			return json_decode(Swfy::$server->table_after->get('after_timer_task','after_tasks'),true);
		}
		return false;
	}

	/**
	 * deleteTickTasks 删除一个实时任务
	 * @param    $timer_id
	 * @return   boolean         
	 */
	public static function deleteTickTasks($timer_id) {
		return \Swoolefy\Core\Timer\Tick::delTicker($timer_id);
	}

	/**
	 * __call
	 * @return   mixed
	 */
	public function __call($action,$args = []) {
		Application::$app->response->end(json_encode([
			'status' => 404,
			'msg' => 'Calling unknown method: ' . get_called_class() . "::$action()",
		]));
		// 直接停止程序往下执行
		throw new \Exception('Calling unknown method: ' . get_called_class() . "::$action()");	
	}

	/**
	 * __callStatic
	 * @return   mixed
	 */
	public static function __callStatic($action,$args = []) {
		Application::$app->response->end(json_encode([
			'status' => 404,
			'msg' => 'Calling unknown static method: ' . get_called_class() . "::$action()",
		]));
		// 直接停止程序往下执行
		throw new \Exception('Calling unknown static method: ' . get_called_class() . "::$action()");
	}

}