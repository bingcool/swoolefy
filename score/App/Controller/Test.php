<?php
namespace App\Controller;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\Application;

class Test {
	public function __construct() {
		
	}
	public function test() {
		$request = Application::$app->request;
		$response = Application::$app->response;

		$view = new \Swoolefy\Core\View;

		$view->assign('name','NKLC');
		$view->display('test.html');
		// $view->returnJson(['name'=>'bing','age'=>1222]);
	}

	public function testajax() {
		$res = ['name'=>'bingcool','age'=>26,'sex'=>1,'info'=>['cloth'=>'red','phone'=>'12222']];
		if(Application::$app->isAjax()) {
			Application::$app->response->end(json_encode($res));
		}
	}
}