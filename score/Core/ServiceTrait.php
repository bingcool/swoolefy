<?php
namespace Swoolefy\Core;

trait ServiceTrait {
	/**
	 * getMasterId 获取当前服务器主进程的PID
	 * @return   int
	 */
	public function getMasterPid() {
		return \Swoolefy\Core\Swfy::$server->master_pid;
	}

	/**
	 * getManagerId 当前服务器管理进程的PID
	 * @return   int
	 */
	public function getManagerPid() {
		return \Swoolefy\Core\Swfy::$server->manager_pid;
	}

	/**
	 * getCurrentWorkerPid 获取当前worker的进程PID 
	 * @return int  
	 */
	public function getCurrentWorkerPid() {
		$workerPid = \Swoolefy\Core\Swfy::$server->worker_pid;
		if($workerPid) {
			return $workerPid;
		}else {
			return posix_getpid();
		}
	}

	/**
	 * getCurrentWorkerId 获取当前处理的worker_id
	 * @return   int
	 */
	public function getCurrentWorkerId() {
		$workerId = \Swoolefy\Core\Swfy::$server->worker_id;
		return $workerId;
	}

	/**
	 * getConnections 服务器当前所有的连接
	 * @return  object 
	 */
	public function getConnections() {
		return \Swoolefy\Core\Swfy::$server->connections;
	}

	/**
	 * getWorkersPid 获取当前所有worker_pid与worker的映射
	 * @Author   huangzengbing
	 * @DateTime 2017-10-20
	 * @param    {String}
	 * @return   [type]        [description]
	 */
	public function getWorkersPid() {
		return \Swoolefy\Core\BaseServer::getWorkersPid();
	}

	/**
	 * getLastError 返回最近一次的错误代码
	 * @return   int 
	 */
	public function getLastError() {
		return \Swoolefy\Core\Swfy::$server->getLastError();
	}

	/**
	 * getStats 获取swoole的状态
	 * @return   array
	 */
	public function getSwooleStats() {
		return \Swoolefy\Core\Swfy::$server->stats();
	}

	/**
	 * getHostName
	 * @return   string
	 */
	public function getHostName() {
		return $this->request->header['host'];
	}

	/**
	 * getLocalIp 获取ip,不包括端口
	 * @return   array
	 */
	public function getLocalIp() {
		return swoole_get_local_ip();
	}

	/**
	 * getIp
	 * @param   $type 返回类型 0:返回IP地址,1:返回IPV4地址数字
	 * @return  string
	 */
	public function getIp($type=0) {
		// 通过nginx的代理
		if(isset($this->request->header['x-real-ip'])) 
	        $ip = $this->request->header['x-real-ip'];
	    else if($this->request->server['remote_addr']) 
	    	 //没通过代理，或者通过代理而没设置x-real-ip的 
	        $ip = $this->request->server['remote_addr'];
	    else $ip = "Unknow";
	    // IP地址合法验证 
	    $long = sprintf("%u", ip2long($ip));
        $ip   = $long ? [$ip, $long] : ['0.0.0.0', 0];
        return $ip[$type];
	}

	/**
	 * getFd
	 * @return  int
	 */
	public function getFd() {
		return $this->request->fd;
	}

	/**
	 * getIncludeFiles description
	 * @return   array|boolean
	 */
	public function getInitIncludeFiles($dir='http') {
		// 获取当前的处理的worker_id
		$workerId = $this->getCurrentWorkerId();

		$dir = ucfirst($dir);
		$filePath = __DIR__.'/../'.$dir.'/'.$dir.'_'.'includes.json';
		if(is_file($filePath)) {
			$includes_string = file_get_contents($filePath);
			if($includes_string) {
				return [
					'current_worker_id' => $workerId,
					'include_init_files' => json_decode($includes_string,true),
				];
			}else {
				return false;
			}
		}

		return false;
		
	}

	/**
	 * getMomeryIncludeFiles 获取执行到目前action为止，swoole server中的该worker中内存中已经加载的class文件
	 * @return  array 
	 */
	public function getMomeryIncludeFiles() {
		$includeFiles = get_included_files();
		$workerId = $this->getCurrentWorkerId();
		return [
			'current_worker_id' => $workerId,
			'include_momery_files' => $includeFiles,
		];
	}

	/**
	 * getConf 获取协议层对应的配置
	 * @param    $protocol
	 * @return   array
	 */
	public function getConf($protocol='http') {
		$protocol = strtolower($protocol);
		switch($protocol) {
			case 'http':
				return \Swoolefy\Http\HttpServer::getConf();
			break;
			case 'websocket':
				return \Swoolefy\Websocket\WebsocketServer::getConf();
			break;
			case 'tcp':
				return \Swoolefy\TcpServer::getConf();
			break;
			default:return \Swoolefy\Http\HttpServer::getConf();
		}	
	}
}