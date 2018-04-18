<?php
namespace App\Process\TickProcess;

use Swoolefy\Core\Application;
use Swoolefy\Core\Process\ProcessController;

class TickTestController extends ProcessController {

	public function ticktest($data) {
		var_dump($data);
		var_dump('这是自定义的定时器进程');

	}
}