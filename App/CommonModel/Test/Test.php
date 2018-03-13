<?php
namespace App\CommonModel\Test;

class Test {
	public function __construct() {
	
	}

	public function getData() {
		return ['name'=>'hello swoolefy-'.rand(1,100)];
	}
}