<?php 
namespace App\Controller;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\Application;
use Swoolefy\Core\ZModel;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Core\MGeneral;
use Swoolefy\Core\MTime;
use swoole_process;

class RedisController extends BController {
	public function set() {
		$this->redis->set('library', 'predis'.rand(1,100));
		dump('yes');
	}

	public function get() {
		$res = $this->redis->get('library');
		dump($res);
	}
}