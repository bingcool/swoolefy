<?php
namespace App\Controller;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\Application;
use Swoolefy\Core\ZModel;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Core\MGeneral;
use Swoolefy\Core\MTime;
use Swoolefy\Core\Task\AppAsyncTask;
use swoole_process;
use Swoolefy\Core\Process\ProcessManager;

class TestController extends BController {

	public $test;

	public function __construct() {
		parent::__construct();
	}	

	public function test() {		
		$name = 'hello,swoolefy!';		
		$books = [
			['name'=>'西游记', 'desc'=>'讲述唐曾四人西天取经的故事'],
			['name'=>'水浒传', 'desc'=>'梁山一百零八好汉']		
		];
		$this->assign('name', $name);
		$this->assign('books', $books);

		$TestModel = ZModel::getInstance('App\Model\Test');
		$data = $TestModel->record();

		$this->display('test.html');
	}

	public function testajax() {
		$return = ['name'=>'李四'];
		return $this->returnJson($return);
	}

	public function testprocess() {
		\Swoolefy\Core\Process\ProcessManager::getInstance()->writeByProcessName('test', 'kmkkmkmkm');
		dump('jjjjjjjjjjjjjjjjjjjj');
	}

}