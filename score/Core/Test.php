<?php
namespace Swoolefy\Core;

class Test {
	static $num =0;

	public function setNum() {
		return self::$num++;
	}
}