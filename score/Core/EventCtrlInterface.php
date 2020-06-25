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

interface EventCtrlInterface {
	/**
	 * init start之前初始化
	 * @param  $args
	 * @return void
	 */
	public function init();

	/**
	 * onStart 
	 * @param  $server
	 * @return void
	 */
	public function start($server);

	/**
	 * onManagerStart 
	 * @param  $server
	 * @return  void
	 */
	public function managerStart($server); 

	/**
	 * onWorkerStart
	 * @param  $server
	 * @return void
	 */
	public function workerStart($server,$worker_id);

	/**
	 * onWorkerStop
	 * @param  $server
	 * @param  $worker_id
	 * @return void
	 */
	public function workerStop($server,$worker_id);

	/**
	 * workerError 
	 * @param  $server
	 * @param  $worker_id
	 * @param  $worker_pid
	 * @param  $exit_code
	 * @param  $signal
	 * @return void
	 */
	public function workerError($server, $worker_id, $worker_pid, $exit_code, $signal);

	/**
	 * workerExit 1.9.17+版本支持
	 * @param  $server
	 * @param  $worker_id
	 * @return void
	 */
	public function workerExit($server, $worker_id);
	/**
	 * onManagerStop
	 * @param $server
	 * @return void
	 */
	public function managerStop($server);
}