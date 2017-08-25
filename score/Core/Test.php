<?php
namespace Swoolefy\Core;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\Application;

class Test {
	
	public static $num = 0;
	
	public $count = 0;

	public function __construct() {
		self::$num = 0;
	}
	public function setNum() {
		$request = Application::$app->request;
		$response = Application::$app->response;

		$smarty = new \Smarty;

		$smarty->setTemplateDir(TEMPLATE_PATH);
		$smarty->assign('name','NKLC');
		$tpl = $smarty->fetch('test.html');

		$response->end($tpl);
	}
}