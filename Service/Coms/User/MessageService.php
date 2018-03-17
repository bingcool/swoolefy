<?php
namespace Service\Coms\User;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\SController;
use Swoolefy\Core\Application;
use Swoolefy\Core\Task\AsyncTask;

class MessageService extends SController {

	public function sendToAll($data) {
		var_dump($data);
		
	}

	public function sendToMyself($data) {
		Swfy::$server->push($this->fd, $data['name']);
	}	
}