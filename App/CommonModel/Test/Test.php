<?php
namespace App\CommonModel\Test;

class Test {
	// 测试
	public function __construct() {}

	public function getData() {
		return ['name'=>'hello swoolefy-'.rand(1,100)];
	}
}