<?php
namespace Swoolefy\Core;

class StartCtrl implements \Swoolefy\Core\StartInterface {
	/**
	 * onStart 
	 * @param    $server
	 * @return          
	 */
	public static function start($server) {
		static::onStart($server);
	}

	/**
	 * onManagerStart 
	 * @param    $server
	 * @return          
	 */
	public static function managerStart($server){
		static::onManagerStart($server);
	}

	/**
	 * onWorkerStart
	 * @param    $server
	 * @return   
	 */
	public static function workerStart($server,$worker_id){
		static::onWorkerStart($server,$worker_id);
	}

	/**
	 * onWorkerStop
	 * @param    $server   
	 * @param    $worker_id
	 * @return             
	 */
	public static function workerStop($server,$worker_id){
		static::onWorkerStop($server,$worker_id);
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
	public static function workerError($server, $worker_id, $worker_pid, $exit_code, $signal) {
		static::onWorkerError($server, $worker_id, $worker_pid, $exit_code, $signal);
	}

	/**
	 * workerExit 1.9.17+版本支持
	 * @param    $server   
	 * @param    $worker_id
	 * @return                 
	 */
	public static function workerExit($server, $worker_id) {
		static::onWorkExit($server, $worker_id);
	}

	/**
	 * onManagerStop
	 * @param    $server
	 * @return          
	 */
	public static function managerStop($server){
		static::onManagerStop($server);
	}
} 