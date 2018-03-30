<?php 
namespace App\Controller;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\Application;
use Swoolefy\Core\ZModel;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Core\MGeneral;
use Swoolefy\Core\MTime;

class MongoController extends BController {
	public function test() {
		$data = ['name'=>'bingcool','sex'=>'nan'];
		$user = $this->mongodb->collection('user');
		$res = $user->insertOne($data);
		// $user->field('*')->find();
		dump($res);
	}

	public function get() {
		$user = $this->mongodb->user;
		$_id = new \MongoDB\BSON\ObjectId('5abc9aba01867b01af198152');
		$data = $user->where(['unid'=> $_id])->field('*')->find();
		// $data = $user->field('*')->find();
		dump($data);

		// dump($this->mongodb->ping());
		// dump($this->mongodb->mongodbClient->__debugInfo());
	}

}