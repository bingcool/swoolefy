<?php
namespace App\Model;

use Swoolefy\Core\Model\BModel;

class Test extends BModel {

	public function record() {

		$name = $_GET['name'];
		return ['name'=>'bingcoolhuang'.rand(1,100).'-'.$name];
	}
}