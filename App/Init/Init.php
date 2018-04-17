<?php
namespace App\Init;

use Swoolefy\Core\StartInit;
use Swoolefy\Core\Process\ProcessManager;
use Swoolefy\Core\Swfy;

class Init extends StartInit {

	public function onInit() {
		ProcessManager::getInstance()->addProcess('test', \App\Process\TestProcess\Test::class);

		ProcessManager::getInstance()->addProcess('tick', \App\Process\TickProcess\Tick::class);

	}

	/**
	 * onWorkerStart
	 * @param    $server
	 * @return   
	 */
	public function onWorkerStart($server,$worker_id) {}
}