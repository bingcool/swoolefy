<?php
namespace App\Controller;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\Application;
use Swoolefy\Core\ZModel;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Core\MGeneral;
use Swoolefy\Core\MTime;

class TestController extends BController {

	public function __construct() {
		parent::__construct();
	}	

	public function test($name,$num) {
		dump('kkkk');
		// $data = $redis->pipeline()->get('foo')->execute();
		// dump($data);

		// $response = $redis->pipeline(function ($pipe) {
		//     for ($i = 0; $i < 10; $i++) {
		//         $pipe->set("key:$i", 'key'.$i);
		//         // $pipe->get("key:$i");
		//     }
		// });

		$this->assign('name', $value);
		$this->assign('books', $books);
		Application::$app->view->test = 9; 
		MGeneral::xhprof();
		$this->display('test.html');
	}

	public function testajax() {
		dump($this->getCurrentWorkerId());
		$res = ['name'=>'bingcool','age'=>26,'sex'=>1,'info'=>['cloth'=>'red','phone'=>'12222']];
		dump($res);
	}

	public function mytest() {
		$data = $this->getModel()->getTest();
		return $data;
	}
}