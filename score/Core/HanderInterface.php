<?php
namespace Swoolefy\Core;

interface HanderInterface {

	public function init($recv);

	public function bootstrap($recv);

	public function run($fd, $recv);

}
