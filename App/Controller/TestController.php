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
		dump('黄增冰 是一个好孩子');
		$this->assign('name', $value);
		$this->assign('books', $books);
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