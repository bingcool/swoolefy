<?php
namespace Swoolefy\Core;

class StartCtrl implements \Swoolefy\Core\StartInterface {
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
	public function workerStart($server,$worker_id){
		static::onWorkerStart($server,$worker_id);
	}

	/**
	 * onWorkerStop
	 * @param    $server   
	 * @param    $worker_id
	 * @return             
	 */
	public function workerStop($server,$worker_id){
		static::onWorkerStop($server,$worker_id);
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