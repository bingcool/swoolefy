<?php
namespace Swoolefy\Core;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\Object;
use Swoolefy\Tcp\TcpServer;
use Swoolefy\Core\Application;

class SController extends Object {

	/**
	 * $previousUrl,记录url
	 * @var array
	 */
	public static $previousUrl = [];

	/**
	 * $selfModel 控制器对应的自身model
	 * @var array
	 */
	public static $selfModel = [];

	/**
	 * $fd 
	 * @var null
	 */
	public $fd = null;

	/**
	 * __construct
	 */
	public function __construct() {

		$this->fd = Application::$app->fd;
	}

	/**
	 * return 返回数据
	 * @param  mixed  $data
	 * @param  string $encode
	 * @return void
	 */
	public function return() {
		$args = func_get_args();
		TcpServer::pack($args, $this->fd);
	}

	/**
	 * isClientPackEof  根据设置判断客户端的分包方式eof
	 * @return boolean
	 */
	public function isClientPackEof() {
		return TcpServer::isClientPackEof();
	}

	/**
	 * isClientPackLength 根据设置判断客户端的分包方式length
	 * @return   boolean       [description]
	 */
	public function isClientPackLength() {
		if($this->isClientPackEof()) {
			return false;
		}
		return true;
	}

	/**
	 * beforeAction 在处理实际action之前执行
	 * @return   mixed
	 */
	public function _beforeAction() {
		return true;
	}

	/**
	 * afterAction 在返回数据之前执行
	 * @return   mixed
	 */
	public function _afterAction() {
		return true;
	}

	/**
	 * __destruct 返回数据之前执行,重新初始化一些静态变量
	 */
	public function __destruct() {
		if(method_exists($this,'_afterAction')) {
			static::_afterAction();
		}
		// 初始化这个变量
		static::$previousUrl = [];
		// 初始化清除所有得单例model实例
		static::$selfModel = [];
	}

	use \Swoolefy\Core\ServiceTrait;
}