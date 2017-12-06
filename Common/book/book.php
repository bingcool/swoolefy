<?php
namespace Common\book;

use Swoolefy\Core\Model\BModel;

class book extends BModel {

	public static $num=0;

	public function &listBooks() {
		self::$num++;
		
		return $data = [
			['name'=>'book1','desc'=>'good'],
			['name'=>'book2','desc'=>'good'],
			['name'=>'book3','desc'=>'good'],
			['name'=>'book4','desc'=>'good'],
			['name'=>'book5','desc'=>self::$num],
		];
	}

	public function _afterAction() {
		self::$num = 0;
	}
}