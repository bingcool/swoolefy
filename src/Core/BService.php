<?php
/**
+----------------------------------------------------------------------
| swoolefy framework bases on swoole extension development, we can use it easily!
+----------------------------------------------------------------------
| Licensed ( https://opensource.org/licenses/MIT )
+----------------------------------------------------------------------
| @see https://github.com/bingcool/swoolefy
+----------------------------------------------------------------------
*/

namespace Swoolefy\Core;

use Swoolefy\Rpc\RpcServer;
use Swoolefy\Udp\UdpHandler;

class BService extends BaseObject {

    use \Swoolefy\Core\ServiceTrait;

    /**
     * $fd
     * @var null
     */
    protected $fd = null;

    /**
	 * $app_conf 应用层配置
	 * @var array
	 */
	protected $app_conf = null;

	/**
	 * $mixed_params 
	 * @var mixed
	 */
	protected $mixed_params;

    /**
     * @var mixed
     */
	protected $client_info;

	/**
	 * __construct
	 */
	public function __construct() {
	    /**@var Swoole $app*/
		$app = Application::getApp();
        $this->fd = $app->fd;
		$this->app_conf = $app->app_conf;
        if(BaseServer::isUdpApp()) {
		    /**@var UdpHandler $app*/
			$this->client_info = $app->getClientInfo();
		}
        if(\Swoole\Coroutine::getCid() > 0) {
			defer(function() {
		    	$this->defer();
        	});
		}
	}

    /**
     * beforeAction 在处理实际action前执行
     * @param string $action
     * @return mixed
     */
    public function _beforeAction(string $action) {
        return true;
    }


    /**
     * @param $mixed_params
     */
    public function setMixedParams($mixed_params) {
        $this->mixed_params = $mixed_params;
    }

    /**
     * @return mixed
     */
    public function getMixedParams() {
        return $this->mixed_params;
    }

	/**
	 * return tcp 发送数据
	 * @param  int    $fd
	 * @param  mixed  $data
	 * @param  array  $header
     * @throws Exception
	 * @return mixed
	 */
	public function send($fd, $data, $header = []) {
		if(!BaseServer::isRpcApp()) {
            throw new \Exception("BService::send() this method only can be called by tcp or rpc server!");
        }
        if(BaseServer::isPackLength()) {
            $payload = [$data, $header];
            $data = \Swoolefy\Rpc\RpcServer::pack($payload);
            return Swfy::getServer()->send($fd, $data);
        }else if(BaseServer::isPackEof()) {
            $text = \Swoolefy\Rpc\RpcServer::pack($data);
            return Swfy::getServer()->send($fd, $text);
        }
	}

    /**
     * sendTo udp 发送数据
     * @param $data
     * @param string $ip
     * @param string $port
     * @param null $server_socket
     * @return mixed
     * @throws \Exception
     */
	public function sendTo($data, $ip = '', $port = '', $server_socket = null) {
        if(empty($ip)) {
            $ip = $this->client_info['address'];
        }
        if(empty($port)) {
            $port = $this->client_info['port'];
        }
		if(!BaseServer::isUdpApp()) {
            throw new \Exception("BService::sendTo() this method only can be called by udp server!");
        }
        if(is_array($data)){
            $data = json_encode($data, JSON_UNESCAPED_UNICODE);
        }
        return Swfy::getServer()->sendto($ip, $port, $data, $server_socket);
	}

	/**
	 * push websocket 发送数据
	 * @param  int    $fd
	 * @param  mixed  $data
	 * @param  int    $opcode
	 * @param  boolean $finish
     * @return boolean
     * @throws Exception
	 */
	public function push($fd, $data, int $opcode = 1, bool $finish = true) {
		if(!BaseServer::isWebsocketApp()) {
            throw new \Exception("BService::push() this method only can be called by websocket server!");
		}
        if(!Swfy::getServer()->isEstablished($fd)) {
            throw new \Exception("Websocket connection closed");
        }

        if(is_array($data)) {
            $data = json_encode($data, JSON_UNESCAPED_UNICODE);
        }

        $result = Swfy::getServer()->push($fd, $data, $opcode, $finish);
        return $result;
		
	}

    /**
     * isClientPackEof  根据设置判断客户端的分包方式eof
     * @return boolean
     * @throws \Exception
     */
	public function isClientPackEof() {
		return RpcServer::isClientPackEof();
	}

    /**
     * isClientPackLength 根据设置判断客户端的分包方式length
     * @return boolean
     * @throws \Exception
     */
	public function isClientPackLength() {
		if($this->isClientPackEof()) {
			return false;
		}
		return true;
	}

    /**
     * getRpcPackHeader  获取rpc的pack头信息,只适用于rpc服务
     * @return array
     * @throws Exception
     */
	public function getRpcPackHeader() {
	    /**@var Swoole $app*/
	    $app = Application::getApp();
		return $app->getRpcPackHeader();
	}

    /**
     * getRpcPackBodyParams 获取rpc的包体数据
     * @return array
     * @throws Exception
     */
	public function getRpcPackBodyParams() {
        /**@var Swoole $app*/
        $app = Application::getApp();
		return $app->getRpcPackBodyParams();
	}

    /**
     * getUdpData 获取udp的数据
     * @return mixed
     * @throws \Exception
     */
	public function getUdpData() {
        /**@var Swoole $app*/
        $app = Application::getApp();
		return $app->getUdpData();
	}

    /**
     * getWebsocketMsg 获取websocket的信息
     * @return mixed
     * @throws \Exception
     */
	public function getWebsocketMsg() {
        /**@var Swoole $app*/
        $app = Application::getApp();
		return $app->getWebsocketMsg();
	}

    /**
     * @return mixed
     */
	public function getClientInfo() {
	    return $this->client_info;
    }


	/**
	 * afterAction 在销毁前执行
     * @param string $action
	 * @return mixed
	 */
	public function _afterAction(string $action) {
		return true;
	}

    /**
     * 控制权实例协程销毁前可以做初始化一些静态变量
     * @return mixed
     */
    public function defer() {}

}