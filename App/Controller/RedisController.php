<?php 
namespace App\Controller;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\Application;
use Swoolefy\Core\ZModel;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Core\MGeneral;
use Swoolefy\Core\MTime;
use Swoolefy\Core\Task\AsyncTask;
use swoole_process;

class RedisController extends BController {
	public function test() {
		$this->redis->set('library', 'predis');
		dump('yes');
	}

	public function get() {
		$res = $this->redis->get('library');
		dump($res);
	}
}