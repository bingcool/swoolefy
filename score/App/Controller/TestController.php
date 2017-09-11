<?php
namespace App\Controller;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\Application;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Http\HttpServer;

class TestController extends BController {

	public function __construct() {
		parent::__construct();
	}
	public function test() {
		var_dump($this->getIncludeFiles());
		$this->assign('name','bingcool'.rand(1,100));
		$this->display('test.html');
	}

	public function testajax() {
		$res = ['name'=>'bingcool','age'=>26,'sex'=>1,'info'=>['cloth'=>'red','phone'=>'12222']];
		if($this->isAjax()) {
			$this->response->end(json_encode($res));
		}
	}

	public function getQuery() {
		self::rememberUrl('mytest','/Test/test');
		
		$this->assign('name','NKLC');
		$url = (self::getPreviousUrl('mytest'));
		$this->redirect($url);

		$this->display('test.html');
	}

	public function mytest($timer_id) {
		echo "task1";
		\Swoolefy\Core\Timer\Tick::delTicker($timer_id);
	}

	public function mytest1($timer_id) {
		echo "task2";
		\Swoolefy\Core\Timer\Tick::delTicker($timer_id);
	}
}