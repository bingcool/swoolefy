<?php
namespace App\Model;

class Test {

	public function record() {
		$name = $_GET['name'];
		return ['name'=>'bingcool'.rand(1,100).'-'.$name];
	}
}