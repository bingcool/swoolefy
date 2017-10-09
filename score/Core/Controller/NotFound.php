<?php
namespace Swoolefy\Core\Controller;

class NotFound extends \Swoolefy\Core\Controller\BController {

	public function page404() {
		return $this->response->end('404 not found!');
	}
}