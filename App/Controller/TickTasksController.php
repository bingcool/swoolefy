<?php
namespace App\Controller;

use Swoolefy\Core\Application;
use Swoolefy\Core\Controller\TaskController;

class TickTasksController extends TaskController {

	public function mytest1($data) {
		var_dump($data);
		echo "总的任务数\n";
		self::test();
		Application::$dump = 'gggg';
	}

	public function test() {
		var_dump('kkkkkkkkkkkkkkk');
	}
}