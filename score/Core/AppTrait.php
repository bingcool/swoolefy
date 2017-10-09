<?php
namespace Swoolefy\Core;

trait AppTrait {
	/**
	 * $previousUrl,记录url
	 * @var array
	 */
	public static $previousUrl = [];
	/**
	 * _beforeAction 
	 * @return   mixed
	 */
	public function _beforeAction() {

	}

	/**
	 * _afterAction
	 * @return   mixed
	 */
	public function _afterAction() {

	}
	/**
	 * isGet
	 * @return boolean
	 */
	public function isGet() {
		return ($this->request->server['request_method'] == 'GET') ? true :false;
	}

	/**
	 * isPost
	 * @return boolean
	 */
	public function isPost() {
		return ($this->request->server['request_method'] == 'POST') ? true :false;
	}

	/**
	 * isPut
	 * @return boolean
	 */
	public function isPut() {
		return ($this->request->server['request_method'] == 'PUT') ? true :false;
	}

	/**
	 * isDelete
	 * @return boolean
	 */
	public function isDelete() {
		return ($this->request->server['request_method'] == 'DELETE') ? true :false;
	}

	/**
	 * isAjax
	 * @return boolean
	 */
	public function isAjax() {
		return (isset($this->request->header['x-requested-with']) && strtolower($this->request->header['x-requested-with']) == 'xmlhttprequest') ? true : false;
	}

	/**
	 * isSsl
	 * @return   boolean
	 */
	public function isSsl() {
	    if(isset($_SERVER['HTTPS']) && ('1' == $_SERVER['HTTPS'] || 'on' == strtolower($_SERVER['HTTPS']))){
	        return true;
	    }elseif(isset($_SERVER['SERVER_PORT']) && ('443' == $_SERVER['SERVER_PORT'] )) {
	        return true;
	    }
	    return false;
	}

	/**
	 * getMethod 
	 * @return   string
	 */
	public function getMethod() {
		return $this->request->server['request_method'];
	}

	/**
	 * getRequestUri
	 * @return string
	 */
	public function getRequestUri() {
		return $this->request->server['path_info'];
	}

	/**
	 * getRoute
	 * @return  string
	 */
	public function getRoute() {
		return $this->request->server['route'];
	}

	/**
	 * getQueryString
	 * @return   string
	 */
	public function getQueryString() {
		return $this->request->server['query_string'];
	}

	/**
	 * getFd
	 * @return  int
	 */
	public function getFd() {
		return $this->request->fd;
	}

	/**
	 * getIp
	 * @return   string
	 */
	public function getIp() {
		// 通过nginx的代理
		if(isset($this->request->header['x-real-ip'])) 
	        $ip = $this->request->header['x-real-ip'];
	    else if($this->request->server['remote_addr']) 
	    	 //没通过代理，或者通过代理而没设置x-real-ip的 
	        $ip = $this->request->server['remote_addr'];
	    else $ip = "Unknow";  
	    return $ip;  
	}

	/**
	 * getProtocol
	 * @return   string
	 */
	public function getProtocol() {
		return $this->request->server['server_protocol'];
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
	 * getHomeUrl
	 * @param    $ssl
	 * @return   string
	 */
	public function getHomeUrl($ssl=false) {
		$protocol = 'http://';
		if($ssl) {
			$protocol = 'https://';
		}
		return $protocol.$this->getHostName().$this->getRequestUri().'?'.$this->getQueryString();
	}

	/**
	 * rememberUrl
	 * @param    $url
	 * @return   void   
	 */
	public function rememberUrl($name=null,$url=null,$ssl=false) {
		if($url && $name) {
			static::$previousUrl[$name] = $url;
		}else {
			// 获取当前的url保存
			static::$previousUrl['home_url'] = $this->getHomeUrl($ssl);
		}
	}

	/**
	 * getPreviousUrl
	 * @param    $name
	 * @return   mixed
	 */
	public function getPreviousUrl($name=null) {
		if($name) {
			if(isset(static::$previousUrl[$name])) {
				return static::$previousUrl[$name];
			}
			return null;
		}else {
			if(isset(static::$previousUrl['home_url'])) {
				return static::$previousUrl['home_url'];
			}

			return null;
		}
	} 

	/**
	 * getRoute
	 * @return array
	 */
	public function getRouteParams() {
		$require_uri = $this->getRoute();
		$route_uri = substr($require_uri,1);
		$route_arr = explode('/',$route_uri);
		if(count($route_arr) == 1){
			$route_arr[1] = 'index';
		}
		return $route_arr;
	}

	/**
	 * getModule 
	 * @return string|null
	 */
	public function getModule() {
		$route_arr = $this->getRouteParams();
		if(count($route_arr) == 3) {
			return $route_arr[0];
		}else {
			return null;
		}
	}

	/**
	 * getController
	 * @return string
	 */
	public function getController() {
		$route_arr = $this->getRouteParams();
		if(count($route_arr) == 3) {
			return $route_arr[1];
		}else {
			return $route_arr[0];
		}
	}

	/**
	 * getAction
	 * @return string
	 */
	public function getAction() {
		$route_arr = $this->getRouteParams();
		return array_pop($route_arr);
	}

	/**
	 * getQuery
	 * @return string
	 */
	public function getQuery() {
		return $this->request->get;
	}

	/**
	 * getView
	 * @return   object
	 */
	public function getView() {
		return Application::$app->view;
	}


	/**
	 * assign
	 * @param   $name
	 * @param   $value
	 * @return  void   
	 */
	public function assign($name,$value) {
		Application::$app->view->assign($name,$value);
	}

	/**
	 * display
	 * @param    $template_file
	 * @return   void             
	 */
	public function display($template_file=null) {
		Application::$app->view->display($template_file);
	}

	/**
	 * fetch
	 * @param    $template_file
	 * @return   void              
	 */
	public function fetch($template_file=null) {
		Application::$app->view->display($template_file);
	}

	/**
	 * returnJson
	 * @param    $data    
	 * @param    $formater
	 * @return   void         
	 */
	public function returnJson($data,$formater = 'json') {
		Application::$app->view->returnJson($data,$formater);
	}

	/**
	 * sendfile
	 * @param    $filename 
	 * @param    $offset   
	 * @param    $length   
	 * @return             
	 */
	public function sendfile($filename, $offset = 0, $length = 0) {
		$this->response->sendfile($filename, $offset = 0, $length = 0);
	}

	/**
	 * redirect
	 * @param    $url
	 * @param    $params eg:['name'=>'ming','age'=>18]
	 * @param    $code default 302
	 * @return   void
	 */
	public function redirect($url,array $params=[],$code=302) {
		$query_string = '';
		if($params) {
			if(strpos($url,'?') > 0) {
				foreach($params as $name=>$value) {
					$query_string .= '&'.$name.'='.$value;
				}
			}else {
				$query_string = '?';
				foreach($params as $name=>$value) {
					$query_string .= $name.'='.$value.'&';
				}

				$query_string = rtrim($query_string,'&');
			}
		}
		$this->status($code);
		$this->response->header('Location', $url.$query_string);
	}

	/**
	 * header,使用链式作用域
	 * @param    $name
	 * @param    $value
	 * @return   object
	 */
	public function header($name,$value) {
		$this->response->header($name, $value);
		return $this->response;
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
					'worker_id' => $workerId,
					'include_files' => json_decode($includes_string,true),
				];
			}else {
				return false;
			}
		}

		return false;
		
	}

	/**
	 * getMomeryIncludeFiles 获取执行到目前action为止，swoole server中的该worker中内存中已经加载的class文件
	 * @return   
	 */
	public function getMomeryIncludeFiles() {
		$includeFiles = get_included_files();
		$workerId = $this->getCurrentWorkerId();
		return [
			'worker_id' => $workerId,
			'include_files' => $includeFiles,
		];
	}

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
	 * dump，调试函数
	 * @param    $var
	 * @param    $echo
	 * @param    $label
	 * @param    $strict
	 * @return   string            
	 */
	public function dump($var, $echo=true, $label=null, $strict=true) {
	    $label = ($label === null) ? '' : rtrim($label) . ' ';
	    if (!$strict) {
	        if (ini_get('html_errors')) {
	            $output = print_r($var, true);
	            $output = '<pre>' . $label . htmlspecialchars($output, ENT_QUOTES) . '</pre>';
	        } else {
	            $output = $label . print_r($var, true);
	        }
	    } else {
	        ob_start();
	        var_dump($var);
	        // 获取终端输出
	        $output = ob_get_clean();
	        if (!extension_loaded('xdebug')) {
	            $output = preg_replace('/\]\=\>\n(\s+)/m', '] => ', $output);
	            $output = '<pre>' . $label . htmlspecialchars($output, ENT_QUOTES) . '</pre>';
	        }
	    }
	    if($echo) {
	    	// 调试环境这个函数使用
	        if(SW_DEBUG) @$this->response->write($output);
	        return null;
	    }else
	        return $output;
	}

	/**
	 * cors 
	 * @return  
	 */
	public function cors() {
		if(isset($this->config['cors']) && is_array($this->config['cors'])) {
			$cors = $this->config['cors'];
			foreach($cors as $k=>$value) {
				if(is_array($value)) {
					$this->response->header($k,implode(',',$value));
				}else {
					$this->response->header($k,$value);
				}
			}
		}
	}

	/**
	 * sendHttpStatus,参考tp的
	 * @param    $code
	 * @return   void     
	 */
	public function status($code) {
		$http_status = array(
			// Informational 1xx
			100,
			101,

			// Success 2xx
			200,
			201,
			202,
			203,
			204,
			205,
			206,

			// Redirection 3xx
			300,
			301,
			302,  // 1.1
			303,
			304,
			305,
			// 306 is deprecated but reserved
			307,

			// Client Error 4xx
			400,
			401,
			402,
			403,
			404,
			405,
			406,
			407,
			408,
			409,
			410,
			411,
			412,
			413,
			414,
			415,
			416,
			417,

			// Server Error 5xx
			500,
			501,
			502,
			503,
			504,
			505,
			509
		);
		if(in_array($code, $http_status)) {
			$this->response->status($code);
		}else {
			if(SW_DEBUG) {
				$this->response->write('error: '.$code .'is not a standard http code!');
			}
		}
	}	
}