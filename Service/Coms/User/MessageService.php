<?php
namespace Service\Coms\User;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\SController;
use Swoolefy\Core\Application;
use Swoolefy\Core\Task\TaskManager;

class MessageService extends SController {

	public function sendToAll($data) {
		var_dump($data);
		$connections = $this->getConnections();
		foreach($connections as $fd) {
			$this->push($fd, $data);
		}

		// 注册任务并执行
		TaskManager::asyncTask(['App/Task/AsyncTask', 'test'], ['swoole']);
		return ;


		
	}

	public function sendToMyself($data) {
		Swfy::$server->push($this->fd, $data['name']);
	}	
}