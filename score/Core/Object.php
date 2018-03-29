<?php
namespace Swoolefy\Core;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\Timer\TickManager;

class Object {

	/**
	 * getTickTasks 获取实时在线的循环定时任务
	 * @return   mixed
	 */
	public static function getTickTasks() {
		return TickManager::getTickTasks();						
	}

	/**
	 * getAfterTasks 获取实时的在线一次性定时任务
	 * @return   mixed
	 */
	public static function getAfterTasks() {
		return TickManager::getAfterTasks();
	}

	/**
	 * deleteTickTasks 删除一个实时任务
	 * @param    $timer_id
	 * @return   boolean         
	 */
	public static function deleteTickTasks($timer_id) {
		return TickManager::clearTimer($timer_id);
	}

	/**
	 * __call
	 * @return   mixed
	 */
	public function __call($action,$args = []) {
			
	}

	/**
	 * __callStatic
	 * @return   mixed
	 */
	public static function __callStatic($action, $args = []) {
		
	}

	/**
	 * _die 异常终端程序执行
	 * @param    $msg
	 * @param    $code
	 * @return   mixed
	 */
	public static function _die($html='',$msg='') {
		
	}

	/**
	 * __toString 
	 * @return string
	 */
	public function __toString() {
		return get_called_class();
	}	

	/**
	 * 直接获取component组件实例
	 */
	public function __get($name) {
		return Application::$app->$name;
	}

}