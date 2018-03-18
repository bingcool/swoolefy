<?php
namespace Swoolefy\Core\Model;

use Swoolefy\Core\Application;

class BModel extends \Swoolefy\Core\SModel {
	/**
	 * $request
	 * @var null
	 */
	public $request = null;
	/**
	 * $response 
	 * @var null
	 */
	public $response = null;

	/**
	 * $session
	 * @var [type]
	 */
	public $session = null;

	/**
	 * __construct
	 */
	public function __construct() {
		parent::__construct();
		// 初始化请求对象和响应对象
		$this->request = Application::$app->request;
		$this->response = Application::$app->response;
		$this->session = Application::$app->session;
	}

	// model的多路复用trait
	use \Swoolefy\Core\ModelTrait, \Swoolefy\Core\AppObjectTrait;
}