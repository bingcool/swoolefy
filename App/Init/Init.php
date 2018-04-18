<?php
namespace App\Init;

use Swoolefy\Core\StartInit;
use Swoolefy\Core\Process\ProcessManager;
use Swoolefy\Core\Swfy;

class Init extends StartInit {

	public function onInit() {
		// 创建一个测试自定义进程
		ProcessManager::getInstance()->addProcess('test', \App\Process\TestProcess\Test::class);
		// 创建一个定时器处理进程
		ProcessManager::getInstance()->addProcess('tick', \App\Process\TickProcess\Tick::class);

	}

	/**
	 * onWorkerStart
	 * @param    $server
	 * @return   
	 */
	public function onWorkerStart($server,$worker_id) {}
}