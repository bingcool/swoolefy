<?php
namespace App\Controller;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\Application;
use Swoolefy\Core\Controller\BController;

class Test extends BController {

	public function __construct() {
		parent::__construct();
	}

	public function test() {
		
		$this->assign('name','hhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhh'.rand(1,100));
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
}