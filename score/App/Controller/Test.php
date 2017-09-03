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
		// $Log = new \Swoolefy\Tool\Log('Application',APP_PATH.'/runtime.log');
  //       $Log->addError('this is a error test!');

		$this->dump($this->getIp());
		$this->assign('name','NKLC');
		$this->display('test.html');

		// $this->returnJson(['name'=>'bing','age'=>5656]);
	}

	public function testajax() {
		$res = ['name'=>'bingcool','age'=>26,'sex'=>1,'info'=>['cloth'=>'red','phone'=>'12222']];
		if($this->isAjax()) {
			$this->response->end(json_encode($res));
		}
	}

	public function getQuery() {
		var_dump('lllll');
		$this->assign('name','NKLC');
		$this->display('test.html');
	}

	public function _beforeAction() {
		var_dump('huagzengbing');
	}

	public function _afterAction() {
		var_dump($this->request);
	}
}