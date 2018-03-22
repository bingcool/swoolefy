<?php
namespace Service\Coms\Udp;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\SController;
use Swoolefy\Core\Application;
use Swoolefy\Core\Task\AsyncTask;

class LogService extends SController {

	public function saveLog($data) {
		var_dump($data);
		var_dump($this->client_info);
	}	
}