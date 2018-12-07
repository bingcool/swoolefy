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

class NotFound extends BService {
	/**
	 * return404 类文件找不到处理
	 * @param  string  $class 
	 * @return        
	 */
	public function return404(string $class) {
		// tcp|rpc服务
		if(BaseServer::isRpcApp()) {
			// rpc服务，server端和client端的header_struct不相同时,默认不作处理
			$is_same_packet_struct = $this->server_client_packet_struct_is_same();
            $data = ['ret'=>404, 'msg'=>$class.' is not found!', 'data'=>''];
			if($is_same_packet_struct) {
				$fd = $this->fd;
				$header = $this->getRpcPackHeader();
				$this->send($fd, $data, $header);
			}
			return $data;
		}else if(BaseServer::isWebsocketApp()) {
			// websocket服务
			$fd = $this->fd;
			$data = ['ret'=>404, 'msg'=>$class.' is not found!', 'data'=>''];
			$this->push($fd, $data, $opcode = 1, $finish = true);
			return $data;
		}
	}

	/**
	 * return500 找不到定义的函数类
	 * @param  string  $class
	 * @param  string  $action
	 * @return void
	 */
	public function return500($class, $action) {
		if(BaseServer::isRpcApp()) {
			// rpc服务，server端和client端的header_struct不相同时,默认不作处理
			$is_same_packet_struct = $this->server_client_packet_struct_is_same();
            $data = ['ret'=>500, 'msg'=>$class.'::'.$action." $action() function undefined!", 'data'=>''];
			if($is_same_packet_struct) {
				$fd = $this->fd;
				$header = $this->getRpcPackHeader();
				$this->send($fd, $data, $header);
			}
			return $data;
		}else if(BaseServer::isWebsocketApp()) {
			// websocket服务
			$fd = $this->fd;
			$data = ['ret'=>500, 'msg'=>$class.'::'.$action." $action() function undefined!", 'data'=>''];
			$this->push($fd, $data, $opcode = 1, $finish = true);
			return $data;
		}
	}

	/**
	 * returnError 直接返回捕捉的错误和异常信息
	 * @return 
	 */
	public function returnError($msg) {
		if(BaseServer::isRpcApp()) {
			// rpc服务，server端和client端的header_struct不相同时,默认不作处理
			$is_same_packet_struct = $this->server_client_packet_struct_is_same();
            $data = ['ret'=>500, 'msg'=>$msg, 'data'=>''];
			if($is_same_packet_struct) {
				$fd = $this->fd;
				$header = $this->getRpcPackHeader();
				$this->send($fd, $data, $header);
			}
			return $data;
		}else if(BaseServer::isWebsocketApp()) {
			// websocket服务
			$fd = $this->fd;
			$data = ['ret'=>500, 'msg'=>$msg, 'data'=>''];
			$this->push($fd, $data, $opcode = 1, $finish = true);
			return $data;
		}
	}

	/**
	 * server_client_packet_struct_is_same 头部结构体是否相同，相同才能直接获取返回，否则要根据client端header_struct的定义生产header头部信息
	 * @return boolean
	 */
	protected function server_client_packet_struct_is_same() {
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