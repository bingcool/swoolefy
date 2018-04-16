<?php
namespace App\Module\book\Model;

use Swoolefy\Core\Model\BModel;

class HotBook extends BModel {
	// 测试
	public function getHotBooks() {
		return [
			['book_name'=>'《lnmp编程》'],
			['book_name'=>'《java开发》'],
		];
	}
}