<?php
namespace Swoolefy\Core;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\BaseServer;

trait ServiceTrait {
	/**
	 * getMasterId 获取当前服务器主进程的PID
	 * @return   int
	 */
	public function getMasterPid() {
		return Swfy::$server->master_pid;
	}

	/**
	 * getManagerId 当前服务器管理进程的PID
	 * @return   int
	 */
	public function getManagerPid() {
		return Swfy::$server->manager_pid;
	}

	/**
	 * getCurrentWorkerPid 获取当前worker的进程PID 
	 * @return int  
	 */
	public function getCurrentWorkerPid() {
		$workerPid = Swfy::$server->worker_pid;
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
		$workerId = Swfy::$server->worker_id;
		return $workerId;
	}

	/**
	 * getConnections 服务器当前所有的连接
	 * @return  object 
	 */
	public function getConnections() {
		return Swfy::$server->connections;
	}

	/**
	 * getWorkersPid 获取当前所有worker_pid与worker的映射
	 * @return   array
	 */
	public function getWorkersPid() {
		return BaseServer::getWorkersPid();
	}

	/**
	 * getLastError 返回最近一次的错误代码
	 * @return   int 
	 */
	public function getLastError() {
		return Swfy::$server->getLastError();
	}

	/**
	 * getStats 获取swoole的状态
	 * @return   array
	 */
	public function getSwooleStats() {
		return Swfy::$server->stats();
	}

	/**
	 * getHostName
	 * @return   string
	 */
	public function getHostName() {
		return $this->request->server['HTTP_HOST'];
	}

    /**
     * getRefererUrl 获取当前页面的上一级页面的来源url
     * @return string | boolean
     */
    public function getRefererUrl() {
        $referer = $this->request->server['HTTP_REFERER'];
        if($referer) {
            return $referer;
        }
        return false;
    }

	/**
	 * getLocalIp 获取ip,不包括端口
	 * @return   array
	 */
	public function getLocalIp() {
		return swoole_get_local_ip();
	}

	/**
     * getClientIP 获取客户端ip
     * @param   $type 返回类型 0:返回IP地址,1:返回IPV4地址数字
     * @return  string
     */
    public function getClientIP($type=0) {
        // 通过nginx的代理
        if(isset($this->request->server['HTTP_X_REAL_IP']) && strcasecmp($this->request->server['HTTP_X_REAL_IP'], "unknown")) {
            $ip = $this->request->server['HTTP_X_REAL_IP'];
        }
        if(isset($this->request->server['HTTP_CLIENT_IP']) && strcasecmp($this->request->server['HTTP_CLIENT_IP'], "unknown")) {
            $ip = $this->request->server["HTTP_CLIENT_IP"];
        }
        if (isset($this->request->server['HTTP_X_FORWARDED_FOR']) and strcasecmp($this->request->server['HTTP_X_FORWARDED_FOR'], "unknown"))
        {
            return $this->request->server['HTTP_X_FORWARDED_FOR'];
        }
        if(isset($this->request->server['REMOTE_ADDR'])) {
            //没通过代理，或者通过代理而没设置x-real-ip的 
            $ip = $this->request->server['REMOTE_ADDR'];
        }
        // IP地址合法验证 
        $long = sprintf("%u", ip2long($ip));
        $ip   = $long ? [$ip, $long] : ['0.0.0.0', 0];
        return $ip[$type];
    }

	/**
	 * getFd 获取当前请求的fd
	 * @return  int
	 */
	public function getFd() {
		return $this->request->fd;
	}

	/**
	 * getIncludeFiles 获取swoole启动时,worker启动前已经include内存的文件
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
			break;
		}	
	}

    /**
     * header,使用链式作用域
     * @param    string  $name
     * @param    string  $value
     * @return   object
     */
    public function header($name,$value) {
        $this->response->header($name, $value);
        return $this->response;
    }

    /**
     * setCookie 设置HTTP响应的cookie信息，与PHP的setcookie()参数一致
     * @param   $key   Cookie名称
     * @param   $value Cookie值
     * @param   $expire 有效时间
     * @param   $path 有效路径
     * @param   $domain 有效域名
     * @param   $secure Cookie是否仅仅通过安全的HTTPS连接传给客户端
     * @param   $httponly 设置成TRUE，Cookie仅可通过HTTP协议访问
     * @return  $this
     */
    public function setCookie($key,$value = '',$expire = 0,$path = '/',$domain = '',$secure = false,$httponly = false) {
        $this->response->cookie($key, $value, $expire, $path, $domain, $secure, $httponly);
        return $this->response;
    }

	/**
	 * getBrowser 获取浏览器
	 * @return   string
	 */
	public function getBrowser() {
        $sys = $this->request->server['HTTP_USER_AGENT'];
        if (stripos($sys, "Firefox/") > 0)
 		{
            preg_match("/Firefox\/([^;)]+)+/i", $sys, $b);
            $exp[0] = "Firefox";
            $exp[1] = $b[1];
        }
        elseif (stripos($sys, "Maxthon") > 0)
        {
            preg_match("/Maxthon\/([\d\.]+)/", $sys, $aoyou);
            $exp[0] = "傲游";
            $exp[1] = $aoyou[1];
        }
        elseif (stripos($sys, "MSIE") > 0)
        {
            preg_match("/MSIE\s+([^;)]+)+/i", $sys, $ie);
            $exp[0] = "IE";
            $exp[1] = $ie[1];
        }
        elseif (stripos($sys, "OPR") > 0)
        {
            preg_match("/OPR\/([\d\.]+)/", $sys, $opera);
            $exp[0] = "Opera";
            $exp[1] = $opera[1];
        }
        elseif (stripos($sys, "Edge") > 0)
        {
            preg_match("/Edge\/([\d\.]+)/", $sys, $Edge);
            $exp[0] = "Edge";
            $exp[1] = $Edge[1];
        }
        elseif (stripos($sys, "Chrome") > 0)
        {
            preg_match("/Chrome\/([\d\.]+)/", $sys, $google);
            $exp[0] = "Chrome";
            $exp[1] = $google[1];
        }
        elseif (stripos($sys, 'rv:') > 0 && stripos($sys, 'Gecko') > 0)
        {
            preg_match("/rv:([\d\.]+)/", $sys, $IE);
            $exp[0] = "IE";
            $exp[1] = $IE[1];
        }
        else
        {
            $exp[0] = "Unkown";
            $exp[1] = "";
        }

        return $exp[0] . '(' . $exp[1] . ')';
    }

    /**
     * getOS 客户端操作系统信息
     * @return  string
     */
    public function getClientOS() {
        $agent = $this->request->server['HTTP_USER_AGENT'];
        if (preg_match('/win/i', $agent) && strpos($agent, '95'))
        {
            $clientOS = 'Windows 95';
        }
        elseif (preg_match('/win 9x/i', $agent) && strpos($agent, '4.90'))
        {
            $clientOS = 'Windows ME';
        }
        elseif (preg_match('/win/i', $agent) && preg_match('/98/i', $agent))
        {
            $clientOS = 'Windows 98';
        }
        elseif (preg_match('/win/i', $agent) && preg_match('/nt 6.0/i', $agent))
        {
            $clientOS = 'Windows Vista';
        }
        elseif (preg_match('/win/i', $agent) && preg_match('/nt 6.1/i', $agent))
        {
            $clientOS = 'Windows 7';
        }
        elseif (preg_match('/win/i', $agent) && preg_match('/nt 6.2/i', $agent))
        {
            $clientOS = 'Windows 8';
        }
        elseif (preg_match('/win/i', $agent) && preg_match('/nt 10.0/i', $agent))
        {
            $clientOS = 'Windows 10';
        }
        elseif (preg_match('/win/i', $agent) && preg_match('/nt 5.1/i', $agent))
        {
            $clientOS = 'Windows XP';
        }
        elseif (preg_match('/win/i', $agent) && preg_match('/nt 5/i', $agent))
        {
            $clientOS = 'Windows 2000';
        }
        elseif (preg_match('/win/i', $agent) && preg_match('/nt/i', $agent))
        {
            $clientOS = 'Windows NT';
        }
        elseif (preg_match('/win/i', $agent) && preg_match('/32/i', $agent))
        {
            $clientOS = 'Windows 32';
        }
        elseif (preg_match('/linux/i', $agent) && preg_match('/android/i', $agent)) 
        {
        	$clientOS = 'Android';
        }elseif(preg_match('/iPhone/i', $agent)) {
        	$clientOS = 'Ios';
        }
        elseif (preg_match('/linux/i', $agent))
        {
            $clientOS = 'Linux';
        }
        elseif (preg_match('/unix/i', $agent))
        {
            $clientOS = 'Unix';
        }
        elseif (preg_match('/sun/i', $agent) && preg_match('/os/i', $agent))
        {
            $clientOS = 'SunOS';
        }
        elseif (preg_match('/ibm/i', $agent) && preg_match('/os/i', $agent))
        {
            $clientOS = 'IBM OS/2';
        }
        elseif (preg_match('/Mac/i', $agent) && preg_match('/PC/i', $agent))
        {
            $clientOS = 'Macintosh';
        }
        elseif (preg_match('/PowerPC/i', $agent))
        {
            $clientOS = 'PowerPC';
        }
        elseif (preg_match('/AIX/i', $agent))
        {
            $clientOS = 'AIX';
        }
        elseif (preg_match('/HPUX/i', $agent))
        {
            $clientOS = 'HPUX';
        }
        elseif (preg_match('/NetBSD/i', $agent))
        {
            $clientOS = 'NetBSD';
        }
        elseif (preg_match('/BSD/i', $agent))
        {
            $clientOS = 'BSD';
        }
        elseif (preg_match('/OSF1/i', $agent))
        {
            $clientOS = 'OSF1';
        }
        elseif (preg_match('/IRIX/i', $agent))
        {
            $clientOS = 'IRIX';
        }
        elseif (preg_match('/FreeBSD/i', $agent))
        {
            $clientOS = 'FreeBSD';
        }
        elseif (preg_match('/teleport/i', $agent))
        {
            $clientOS = 'teleport';
        }
        elseif (preg_match('/flashget/i', $agent))
        {
            $clientOS = 'flashget';
        }
        elseif (preg_match('/webzip/i', $agent))
        {
            $clientOS = 'webzip';
        }
        elseif (preg_match('/offline/i', $agent))
        {
            $clientOS = 'offline';
        }
        else
        {
            $clientOS = 'Unknown';
        }

        return $clientOS;
    }
}