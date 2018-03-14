<?php
namespace App\Controller;

use Swoolefy\Core\Application;
use Swoolefy\Core\Controller\BController;

class IndexController extends BController {

	public function index() {
		$this->response->end('hello word!');
	}

}