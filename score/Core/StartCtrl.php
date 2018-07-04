<?php
/**
+----------------------------------------------------------------------
| swoolefy framework bases on swoole extension development, we can use it easily!
+----------------------------------------------------------------------
| Licensed ( https://opensource.org/licenses/MIT )
+----------------------------------------------------------------------
| Author: bingcool <bingcoolhuang@gmail.com || 2437667702@qq.com>
+----------------------------------------------------------------------
*/

namespace Swoolefy\Core;

class StartCtrl implements \Swoolefy\Core\StartInterface {

	/**
	 * init start之前初始化
	 * @param  $args
	 * @return void
	 */
	public function init() {
		static::onInit();
	}

	/**
	 * onStart 
	 * @param    $server
	 * @return          
	 */
	public function start($server) {
		static::onStart($server);
	}

	/**
	 * onManagerStart 
	 * @param    $server
	 * @return          
	 */
	public function managerStart($server){
		static::onManagerStart($server);
	}

	/**
	 * onWorkerStart
	 * @param    $server
	 * @return   
	 */
	public function workerStart($server, $worker_id){
		static::onWorkerStart($server, $worker_id);
	}

	/**
	 * onWorkerStop
	 * @param    $server   
	 * @param    $worker_id
	 * @return             
	 */
	public function workerStop($server, $worker_id){
		static::onWorkerStop($server, $worker_id);
	}

	/**
	 * workerError 
	 * @param    $server    
	 * @param    $worker_id 
	 * @param    $worker_pid
	 * @param    $exit_code 
	 * @param    $signal    
	 * @return              
	 */
	public function workerError($server, $worker_id, $worker_pid, $exit_code, $signal) {
		static::onWorkerError($server, $worker_id, $worker_pid, $exit_code, $signal);
	}

	/**
	 * workerExit 1.9.17+版本支持
	 * @param    $server   
	 * @param    $worker_id
	 * @return                 
	 */
	public function workerExit($server, $worker_id) {
		static::onWorkerExit($server, $worker_id);
	}

	/**
	 * onManagerStop
	 * @param    $server
	 * @return          
	 */
	public function managerStop($server){
		static::onManagerStop($server);
	}
} 