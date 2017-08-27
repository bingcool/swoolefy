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

		$smarty = new \Smarty;

		$smarty->setTemplateDir(TEMPLATE_PATH);
		$smarty->assign('name','NKLC');
		$tpl = $smarty->fetch('test.html');

		$response->end($tpl);
	}

	public function testajax() {
		$res = ['name'=>'bingcool','age'=>26,'sex'=>1,'info'=>['cloth'=>'red','phone'=>'12222']];
		Application::$app->response->end(json_encode($res));
	}
}