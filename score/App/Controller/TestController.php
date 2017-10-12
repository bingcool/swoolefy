<?php
namespace App\Controller;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\Application;
use Swoolefy\Core\ZModel;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Http\HttpServer;

class TestController extends BController {

	public function __construct() {
		parent::__construct();
		$class = \Swoolefy\Core\HttpRoute::$RoutefileMap;
		var_dump($class);
	}

	public function test() {
		
		$this->assign('name','bingcool'.rand(1,100));
		$this->display('test.html');
	}

	public function testajax() {
		$res = ['name'=>'bingcool','age'=>26,'sex'=>1,'info'=>['cloth'=>'red','phone'=>'12222']];
		$this->returnJson($res);

	}

	public function testRedirect() {
		self::rememberUrl('mytest','/Test/mytest');
		$this->assign('name','NKLC');
		$url = (parent::getPreviousUrl('mytest'));
		$this->redirect($url);
		return;
	}

	public function mytest() {
		$this->display('test.html');
	}

	public function mytest1($timer_id) {
		echo "task2";
		\Swoolefy\Core\Timer\Tick::delTicker($timer_id);
	}
}