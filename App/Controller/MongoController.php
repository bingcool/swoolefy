<?php 
namespace App\Controller;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\Application;
use Swoolefy\Core\ZModel;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Core\MGeneral;
use Swoolefy\Core\MTime;
use Swoolefy\Core\Task\AsyncTask;

class MongoController extends BController {
	public function test() {
		$data = ['name'=>'bingcool','sex'=>'nan'];
		$user = $this->mongodb->collection('user');
		$res = $user->insertOne($data);
		// $user->field('*')->find();
		dump($res);
		dump($user);
	}

	public function get() {
		$user = $this->mongodb->collection('user');
		$data = $user->field('*')->find();
		dump($data);

	}

}