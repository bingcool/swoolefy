<?php
namespace Service\Coms\Udp;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\SController;
use Swoolefy\Core\Application;
use Swoolefy\Core\Task\AsyncTask;
use Swoolefy\Core\Task\TaskManager;

class LogService extends SController {

	public function saveLog($data) {
		var_dump($data);
		var_dump($this->client_info);
		var_dump('测试异步任务');

		// 注册任务并执行
		TaskManager::asyncTask(['App/Task/AsyncTask', 'test'], ['swoole']);
		return ;
	}	
}