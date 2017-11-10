<?php
namespace App\Module\book;

use Swoolefy\Core\ZModel;
use Swoolefy\Core\Controller\BController;

class BookController extends BController {

	public function test() {
		// $Test = ZModel::getInstance('App\Model\Test');
		$Test = $this->getModel();
		$data = $Test->record();
		// $this->returnJson($data);
		$this->assign('name',$data['name']);
		$this->display('test.html');
	} 
}