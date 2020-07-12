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
     * @param string $class
     * @return mixed
     * @throws \Exception
     */
	public function return404(string $class) {
	    $ret = 404;
	    $msg = "Not Found Class {$class}";
        $responseData = Application::buildResponseData($ret, $msg);
		if(BaseServer::isRpcApp()) {
			$is_same_packet_struct = $this->serverClientPacketStructSame();
            if($is_same_packet_struct) {
                $fd = Application::getApp()->getFd();
                $header = $this->getRpcPackHeader();
                $this->send($fd, $responseData, $header);
            }

		}else if(BaseServer::isWebsocketApp()) {
			$fd = Application::getApp()->getFd();
			$this->push($fd, $responseData, $opcode = 1, $finish = true);
		}
		return $responseData;
	}

    /**
     * return500 找不到定义的函数类
     * @param string $class
     * @param string $action
     * @return mixed
     * @throws \Exception
     */
	public function return500(string $class, string $action) {
	    $ret = 500;
	    $msg = "Call Undefined Function Of {$class}::{$action}";
        $responseData = Application::buildResponseData($ret, $msg);
		if(BaseServer::isRpcApp()) {
			$is_same_packet_struct = $this->serverClientPacketStructSame();
			if($is_same_packet_struct) {
				$fd = Application::getApp()->getFd();
				$header = $this->getRpcPackHeader();
				$this->send($fd, $responseData, $header);
			}
		}else if(BaseServer::isWebsocketApp()) {
			$fd = Application::getApp()->getFd();
			$this->push($fd, $responseData, $opcode = 1, $finish = true);
		}
		return $responseData;
	}

    /**
     * returnError 直接返回捕捉的错误和异常信息
     * @param string $msg
     * @return mixed
     * @throws \Exception
     */
	public function returnError(string $msg) {
        $ret = 500;
        $responseData = Application::buildResponseData($ret, $msg);
        if(BaseServer::isRpcApp()) {
			$is_same_packet_struct = $this->serverClientPacketStructSame();
			if($is_same_packet_struct) {
				$fd = Application::getApp()->getFd();
				$header = $this->getRpcPackHeader();
				$this->send($fd, $responseData, $header);
			}

		}else if(BaseServer::isWebsocketApp()) {
			$fd = Application::getApp()->getFd();
			$this->push($fd, $responseData, $opcode = 1, $finish = true);
		}
		return $responseData;
	}

	/**
	 * serverClientPacketStructSame 头部结构体是否相同，相同才能直接获取返回，否则要根据client端header_struct的定义生产header头部信息
	 * @return boolean
	 */
	protected function serverClientPacketStructSame() {
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