<?php
namespace Swoolefy\Websocket;

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
		// if($worker_id == 0) {
		// 	$tid = \Swoolefy\Core\Timer\Tick::afterTimer(10000,['App\\Controller\\Test','mytest'],[['name'=>'bing']]);
		// }
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