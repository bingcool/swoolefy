<?php
namespace App\Init;

use Swoolefy\Core\StartInit;
use Swoolefy\Core\Process\ProcessManager;
use Swoolefy\Core\Swfy;

class Init extends StartInit {

	public function onInit() {
		
		ProcessManager::getInstance()->addProcess('test', \App\Process\Test::class);
		ProcessManager::addProcess('test2', \App\Process\Test::class);
	}
}