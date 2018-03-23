<?php
namespace Swoolefy\Core;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\Object;
use Swoolefy\Core\Application;
use Swoolefy\Core\BaseServer;

class SController extends Object {

	/**
	 * $config 应用层配置
	 * @var null
	 */
	public $config = null;

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
		$this->config = Application::$app->config;

		// udp协议设置
		if(BaseServer::getServiceProtocol() == SWOOLEFY_UDP) {
			$this->client_info = Application::$app->client_info;
		}else {
			$this->client_info = null;
		}
	}

	/**
	 * return tcp 发送数据
	 * @param  mixed  $data
	 * @param  string $encode
	 * @return void
	 */
	public function send($fd, $data, $header = []) {
		if(BaseServer::getServiceProtocol() == SWOOLEFY_TCP) {
			$args = [$data, $header];
			$data = \Swoolefy\Tcp\TcpServer::pack($args);
			Swfy::$server->send($fd, $data);
		}else {
			throw new \Exception("this method only can be called by tcp or rpc server!");
		}
		
	}

	/**
	 * sendto udp 发送数据
	 * @param    int      $ip  
	 * @param    int      $port
	 * @param    mixed    $data
	 * @param    int      $server_socket
	 * @return   void
	 */
	public function sendto($ip, $port, $data, $server_socket = -1) {
		if(BaseServer::getServiceProtocol() == SWOOLEFY_UDP) {
			if(is_array($data)){
				$data = json_encode($data);
			}
			Swfy::$server->sendto($ip, $port, $data, $server_socket);
		}else {
			throw new \Exception("this method only can be called by udp server!");
		}
	}

	/**
	 * push websocket 发送数据
	 * @param  int    $fd
	 * @param  mixed  $data
	 * @param  int    $opcode
	 * @param  boolean $finish
	 * @return boolean
	 */
	public function push($fd, $data, $opcode = 1, $finish = true) {
		// 只能由websoccket调用
		if(BaseServer::getServiceProtocol() == SWOOLEFY_WEBSOCKET) {
			if(is_array($data)){
				$data = json_encode($data);
			}
			$result = Swfy::$server->push($fd, $data, $opcode, $finish);
			return $result;
		}else {
			throw new \Exception("this method only can be called by websocket server!");
		}
		
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
		// 初始化清除所有得单例model实例
		static::$selfModel = [];
	}

	use \Swoolefy\Core\ServiceTrait;
}