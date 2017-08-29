<?php
namespace App\Controller;

use Swoolefy\Core\Controller\BController;

class site extends BController {
	/**
	 * @param    {String}
	 * @return   [type]        [description]
	 */
	public function index() {
		$this->response->end('<h3>welcome to use swoolefy!</h3>');
	}
}