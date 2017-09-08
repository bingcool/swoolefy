<?php
namespace Swoolefy\Http;

class StartInit extends \Swoolefy\Core\StartCtrl {
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
	public function onManagerStart($server){
		
	}

	/**
	 * onWorkerStart
	 * @param    $server
	 * @return   
	 */
	public function onWorkerStart($server,$worker_id){
		if($worker_id == 0) {
			$tid = \Swoolefy\Core\Timer\Tick::tickTimer(5000,['App\\Controller\\Test','mytest'],[]);
			$tasks = \Swoolefy\Core\Timer\Tick::getRunTickTask();
		}
	}

	/**
	 * onWorkerStop
	 * @param    $server   
	 * @param    $worker_id
	 * @return             
	 */
	public function onWorkerStop($server,$worker_id){
		
	}

	/**
	 * onManagerStop
	 * @param    $server
	 * @return          
	 */
	public function onManagerStop($server){
		
	}
}