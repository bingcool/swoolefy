<?php
namespace App\Process\TickProcess;

use Swoolefy\Core\Application;
use Swoolefy\Core\Object;

class TickController extends Object {

	public function ticktest($data) {
		var_dump($data);
		echo "总的任务数\n";
		self::test();
	}

	public function test() {
		var_dump('kkkkkkkkkkkkkkk');
	}
}