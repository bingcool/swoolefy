<?php
/**
+----------------------------------------------------------------------
| swoolefy framework bases on swoole extension development, we can use it easily!
+----------------------------------------------------------------------
| Licensed ( https://opensource.org/licenses/MIT )
+----------------------------------------------------------------------
| Author: bingcool <bingcoolhuang@gmail.com || 2437667702@qq.com>
+----------------------------------------------------------------------
*/

namespace Swoolefy\Core;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\Hook;
use Swoolefy\Tcp\TcpServer;
use Swoolefy\Core\BaseObject;
use Swoolefy\Core\BaseServer;
use Swoolefy\Core\Application;

class BService extends BaseObject {

	/**
	 * $config 应用层配置
	 * @var null
	 */
	public $config = null;

	/**
	 * $selfModel 控制器对应的自身model
	 * @var array
	 */
	public $selfModel = [];

	/**
	 * $fd 
	 * @var null
	 */
	public $fd = null;

	/**
	 * $mixed_params 
	 * @var 
	 */
	public $mixed_params;

	/**
	 * __construct
	 */
	public function __construct() {
		$this->fd = Application::getApp()->fd;
		$this->config = Application::getApp()->config;

		// udp协议设置
		if(BaseServer::getServiceProtocol() == SWOOLEFY_UDP) {
			$this->client_info = Application::getApp()->client_info;
		}else {
			$this->client_info = null;
		}
	}

	/**
	 * return tcp 发送数据
	 * @param  int    $fd
	 * @param  mixed  $data
	 * @param  string $encode
	 * @return void
	 */
	public function send($fd, $data, $header = []) {
		if(BaseServer::getServiceProtocol() == SWOOLEFY_TCP) {
			$args = [$data, $header];
			$data = \Swoolefy\Tcp\TcpServer::pack($args);
			Swfy::getServer()->send($fd, $data);
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
			Swfy::getServer()->sendto($ip, $port, $data, $server_socket);
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
			$result = Swfy::getServer()->push($fd, $data, $opcode, $finish);
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
	 * @return   boolean
	 */
	public function isClientPackLength() {
		if($this->isClientPackEof()) {
			return false;
		}
		return true;
	}

	/**
	 * getRpcPackHeader  获取rpc的pack头信息,只适用于rpc服务
	 * @return   array
	 */
	public function getRpcPackHeader() {
		return Application::getApp()->getRpcPackHeader();
	}

	/**
	 * getRpcPackBodyParams 获取rpc的包体数据
	 * @return mixed
	 */
	public function getRpcPackBodyParams() {
		return Application::getApp()->getRpcPackBodyParams();
	}

	/**
	 * getUdpData 获取udp的数据
	 * @return mixed
	 */
	public function getUdpData() {
		return Application::getApp()->getUdpData();
	}

	/**
	 * getWebsockMsg 获取websocket的信息
	 * @return mixed
	 */
	public function getWebsockMsg() {
		return Application::getApp()->getWebsockMsg();
	}

	/**
	 * beforeAction 在处理实际action前执行
	 * @return   mixed
	 */
	public function _beforeAction() {
		return true;
	}

	/**
	 * afterAction 在销毁前执行
	 * @return   mixed
	 */
	public function _afterAction() {
		return true;
	}

	/**
	 * __destruct 重新初始化一些静态变量
	 */
	public function __destruct() {
		if(method_exists($this,'_afterAction')) {
			static::_afterAction();
		}
	}

	use \Swoolefy\Core\ServiceTrait;
}