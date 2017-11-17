<?php
namespace App\Controller;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\Application;
use Swoolefy\Core\ZModel;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Http\HttpServer;
use Swoolefy\Core\MGeneral;
use Swoolefy\Core\MTime;

class TestController extends BController {

	public function __construct() {
		parent::__construct();
	}	
	/**
	 * Undocumented function
	 *
	 * @return void
	 */
	public function test() {		
		// Application::$app->db->test();
		$data = $this->getModel()->getTest();
		$this->assign('name',$data['name']);
		$this->display('test.html');
	}

	public function testajax() {
		$res = ['name'=>'bingcool','age'=>26,'sex'=>1,'info'=>['cloth'=>'red','phone'=>'12222']];
		dump($res);
	}

	public function mytest() {
		$data = $this->getModel()->getTest();
		return $data;
	}
}