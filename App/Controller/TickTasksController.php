<?php
namespace App\Controller;

class TickTasksController extends \Swoolefy\Core\Object {
	// test task
	public function mytest1($data) {
		var_dump($data);
		echo "总的任务数\n";
		self::test();
	}

	public function test() {
		var_dump('kkkkkkkkkkkkkkk');
	}
}