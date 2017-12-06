<?php
namespace App\CommonModel\Test;

class Test {
	public function __construct() {
	
	}

	public function getData() {
		return ['name'=>'黄增冰hello swoolefy-'.rand(1,100)];
	}
}