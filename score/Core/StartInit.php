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

class StartInit extends \Swoolefy\Core\StartCtrl {

	use \Swoolefy\Core\SingletonTrait;

	/**
	 * init swoole服务start启动之前初始化
	 * 可创建自定义进程process
	 * @return void
	 */
	public function onInit() {

	}

	/**
	 * onStart 
	 * @param    $server
	 * @return          
	 */
	public function onStart($server) {
		
	}

	/**
	 * onManagerStart 
	 * @param    $server
	 * @return          
	 */
	public function onManagerStart($server) {
		
	}

	/**
	 * onWorkerStart
	 * @param    $server
	 * @return   
	 */
	public function onWorkerStart($server,$worker_id) {
		
	}

	/**
	 * onWorkerStop
	 * @param    $server   
	 * @param    $worker_id
	 * @return             
	 */
	public function onWorkerStop($server,$worker_id) {
		
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
	public function onWorkerError($server, $worker_id, $worker_pid, $exit_code, $signal) {

	}

	/**
	 * workerExit 1.9.17+版本支持
	 * @param    $server   
	 * @param    $worker_id
	 * @return                 
	 */
	public function onWorkerExit($server, $worker_id) {

	}

	/**
	 * onManagerStop
	 * @param    $server
	 * @return          
	 */
	public function onManagerStop($server) {
		
	}
}