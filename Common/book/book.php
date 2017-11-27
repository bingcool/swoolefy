<?php
namespace Common\book;

use Swoolefy\Core\Model\BModel;

class book extends BModel {

	public function listBooks() {
		return [
			['name'=>'book1','desc'=>'good'],
			['name'=>'book2','desc'=>'good'],
			['name'=>'book3','desc'=>'good'],
			['name'=>'book4','desc'=>'good'],
		];
	}
}