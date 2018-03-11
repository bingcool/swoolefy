<?php
namespace Service\Coms\Book;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\SController;
use Swoolefy\Core\Application;

class Bookmanage extends SController {

	public function test($params, $hello) {
		$data = ['name'=>'bingcool','sex'=>'男','num'=>rand(20,100)];
		$this->return($data);
	}
}