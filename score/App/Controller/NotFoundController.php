<?php
namespace App\Controller;

class NotFoundController extends \Swoolefy\Core\Controller\BController {

	public function page404() {
		$this->display('page404.html');
	}
}