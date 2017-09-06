<?php
namespace Swoolefy\Core;

interface StartInterface {

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
	 * onManagerStop
	 * @param    $server
	 * @return          
	 */
	public function managerStop($server);
}