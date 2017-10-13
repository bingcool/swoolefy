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
	public function onManagerStart($server) {
		
	}

	/**
	 * onWorkerStart
	 * @param    $server
	 * @return   
	 */
	public function onWorkerStart($server,$worker_id) {
		if($worker_id == 0) {
			// \Swoolefy\Core\Timer\Tick::tickTimer(10000,['App\\Controller\\Test','mytest'],[]);
			// \Swoolefy\Core\Timer\Tick::tickTimer(20000,['App\\Controller\\TickTasksController','mytest1'],[]);
			// \Swoolefy\Core\Timer\Tick::tickTimer(15000,['App\\Controller\\TickTasksController','mytest1'],[]);
			// \Swoolefy\Core\Timer\Tick::tickTimer(18000,['App\\Controller\\TickTasksController','mytest1'],[]);
			// $tid1 = \Swoolefy\Core\Timer\Tick::afterTimer(5000,['App\\Controller\\Test','mytest'],[]);
		}
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
	 * onManagerStop
	 * @param    $server
	 * @return          
	 */
	public function onManagerStop($server) {
		
	}
}