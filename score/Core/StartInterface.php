<?php
namespace Swoolefy\Core;

interface StartInterface {

	/**
	 * init start之前初始化
	 * @param  $args
	 * @return 
	 */
	public function init();

	/**
	 * onStart 
	 * @param    $server
	 * @return          
	 */
	public function start($server);

	/**
	 * onManagerStart 
	 * @param    $server
	 * @return          
	 */
	public function managerStart($server); 

	/**
	 * onWorkerStart
	 * @param    $server
	 * @return   
	 */
	public function workerStart($server,$worker_id);

	/**
	 * onWorkerStop
	 * @param    $server   
	 * @param    $worker_id
	 * @return             
	 */
	public function workerStop($server,$worker_id);

	/**
	 * workerError 
	 * @param    $server    
	 * @param    $worker_id 
	 * @param    $worker_pid
	 * @param    $exit_code 
	 * @param    $signal    
	 * @return              
	 */
	public function workerError($server, $worker_id, $worker_pid, $exit_code, $signal);

	/**
	 * workerExit 1.9.17+版本支持
	 * @param    $server   
	 * @param    $worker_id
	 * @return                 
	 */
	public function workerExit($server, $worker_id);
	/**
	 * onManagerStop
	 * @param    $server
	 * @return          
	 */
	public function managerStop($server);
}