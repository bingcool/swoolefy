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
		// $this->assign('name','NKLC');
		// $this->display('test.html');
		// var_dump('hello');
		$this->returnJson(['name'=>'bing','age'=>5656]);
	}

	public function testajax() {
		$res = ['name'=>'bingcool','age'=>26,'sex'=>1,'info'=>['cloth'=>'red','phone'=>'12222']];
		if($this->isAjax()) {
			$this->response->end(json_encode($res));
		}
	}
}