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
	}
	public function test() {
		$Test = $this->getModel();
		$data = $Test->record();
		$data1 = $Test->getTest();
		dump($data1);
		dump($this->getInitIncludeFiles());
		dump($this->getMomeryIncludeFiles());
		$this->assign('name',$data['name']);
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
		$data = $this->getModel()->getTest();
		return $data;
	}

	
}