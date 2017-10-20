<?php
namespace App\Model;

use Swoolefy\Core\Model\BModel;
use Swoolefy\Core\ZModel;

class Test extends BModel {
	public static $num = 0;
	public function record() {
		global $count;
		$count++ ;
		self::$num++;
		$name = $_GET['name'];
		return ['name'=>'bingcoolhuang'.rand(1,100).'-'.$name.'-'.self::$num.'-'.$count];
	}

	public function getTest() {
		$TestModel = ZModel::getInstance('App\CommonModel\Test\Test');
		return $TestModel->getData();
	}

	public function _afterAction() {
		//定义的静态变量和全局变量 
		self::$num = 0;
		global $count;
		$count = 0;
	}
}