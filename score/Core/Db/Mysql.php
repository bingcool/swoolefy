<?php
namespace Swoolefy\Core\Db;

class Mysql extends Driver {

	public function __construct($config=[]) {
		parent::__construct($config);
	}

	public function test() {
		$db = $this->connect();
		dump($this);
	}
}