<?php
namespace App\Module\book\Model;

use Swoolefy\Core\Model\BModel;

class Book extends BModel {

	public function record() {
		$name = $_GET['name'];
		return ['name'=>'bingcoolhuang-MODULE'.rand(1,100).'-'.$name];
	}
}