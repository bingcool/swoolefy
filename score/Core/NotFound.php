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

use Swoolefy\Core\Application;

class NotFound extends BService {
	/**
	 * return404 类文件找不到处理
	 * @param  string  $class 
	 * @return mixed
	 */
	public function return404(string $class) {
        $response = ['ret'=>404, 'msg'=>"{$class} is not found", 'data'=>''];
		// tcp|rpc服务
		if(BaseServer::isRpcApp()) {
			// rpc服务，server端和client端的header_struct不相同时,默认不作处理
			$is_same_packet_struct = $this->serverClientPacketstructSame();
			if($is_same_packet_struct) {
				$fd = Application::getApp()->getFd();
				$header = $this->getRpcPackHeader();
				$this->send($fd, $response, $header);
			}

		}else if(BaseServer::isWebsocketApp()) {
			// websocket服务
			$fd = Application::getApp()->getFd();
			$this->push($fd, $response, $opcode = 1, $finish = true);
		}
		return $response;
	}

	/**
	 * return500 找不到定义的函数类
	 * @param  string  $class
	 * @param  string  $action
	 * @return mixed
	 */
	public function return500($class, $action) {
        $response = ['ret'=>500, 'msg'=>"{$class}::{$action} is undefined", 'data'=>''];
		if(BaseServer::isRpcApp()) {
			// rpc服务，server端和client端的header_struct不相同时,默认不作处理
			$is_same_packet_struct = $this->serverClientPacketstructSame();
			if($is_same_packet_struct) {
				$fd = Application::getApp()->getFd();
				$header = $this->getRpcPackHeader();
				$this->send($fd, $response, $header);
			}
		}else if(BaseServer::isWebsocketApp()) {
			// websocket服务
			$fd = Application::getApp()->getFd();
			$this->push($fd, $response, $opcode = 1, $finish = true);
		}
		return $response;
	}

	/**
	 * returnError 直接返回捕捉的错误和异常信息
     * @param  string  $msg
	 * @return mixed
	 */
	public function returnError($msg) {
        $response = ['ret'=>500, 'msg'=>$msg, 'data'=>''];
		if(BaseServer::isRpcApp()) {
			// rpc服务，server端和client端的header_struct不相同时,默认不作处理
			$is_same_packet_struct = $this->serverClientPacketstructSame();
			if($is_same_packet_struct) {
				$fd = Application::getApp()->getFd();
				$header = $this->getRpcPackHeader();
				$this->send($fd, $response, $header);
			}

		}else if(BaseServer::isWebsocketApp()) {
			// websocket服务
			$fd = Application::getApp()->getFd();
			$this->push($fd, $response, $opcode = 1, $finish = true);
		}
		return $response;
	}

	/**
	 * serverClientPacketstructSame 头部结构体是否相同，相同才能直接获取返回，否则要根据client端header_struct的定义生产header头部信息
	 * @return boolean
	 */
	protected function serverClientPacketstructSame() {
		// 获取协议层配置
		$conf = Swfy::getConf();
		$server_pack_header_struct = $conf['packet']['server']['pack_header_struct'];
		$client_pack_header_struct = $conf['packet']['client']['pack_header_struct'];
		if(is_array($server_pack_header_struct) && is_array($client_pack_header_struct)) {
			$server_num = count(array_keys($server_pack_header_struct));
			$client_num = count(array_keys($client_pack_header_struct));
			if($server_num == $client_num) {
				$is_same_packet_struct = true;
				foreach($server_pack_header_struct as $k=>$value) {
					if($client_pack_header_struct[$k] != $value) {
						$is_same_packet_struct = false;
					}
				}
				return $is_same_packet_struct;
			}
		}
		return false;
	}
}