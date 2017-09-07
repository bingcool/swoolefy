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
	 * @return       
	 */
	public function rememberUrl($name=null,$url=null,$ssl=false) {
		if($url && $name) {
			static::$previousUrl[$name] = $url;
		}else {
			// 获取当前的url保存
			static::$previousUrl['swoolefy_current_home_url'] = $this->getHomeUrl($ssl);
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
			if(isset(static::$previousUrl['swoolefy_current_home_url'])) {
				return static::$previousUrl['swoolefy_current_home_url'];
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
	 * getModel 
	 * @return string|null
	 */
	public function getModel() {
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
	 * assign
	 * @param   $name
	 * @param   $value
	 * @return       
	 */
	public function assign($name,$value) {
		Application::$app->view->assign($name,$value);
	}

	/**
	 * display
	 * @param    $template_file
	 * @return                 
	 */
	public function display($template_file=null) {
		Application::$app->view->display($template_file);
	}

	/**
	 * fetch
	 * @param    $template_file
	 * @return                 
	 */
	public function fetch($template_file=null) {
		Application::$app->view->display($template_file);
	}

	/**
	 * returnJson
	 * @param    $data    
	 * @param    $formater
	 * @return            
	 */
	public function returnJson($data,$formater = 'json') {
		Application::$app->view->returnJson($data,$formater);
	}

	public function sendfile($filename, $offset = 0, $length = 0) {
		$this->response->sendfile($filename, $offset = 0, $length = 0);
	}

	/**
	 * redirect
	 * @param    $url
	 * @param    $params eg:['name'=>'ming','age'=>18]
	 * @param    $code default 302
	 * @return   
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
	 * getLastError 返回最近一次的错误代码
	 * @return   int 
	 */
	public function getLastError() {
		return Swfy::$server->getLastError();
	}

	/**
	 * getStats 获取swoole的状态
	 * @return   [type]        [description]
	 */
	public function getSwooleStats() {
		return Swfy::$server->stats();
	}

	// public function 

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
	        $output = ob_get_clean();
	        if (!extension_loaded('xdebug')) {
	            $output = preg_replace('/\]\=\>\n(\s+)/m', '] => ', $output);
	            $output = '<pre>' . $label . htmlspecialchars($output, ENT_QUOTES) . '</pre>';
	        }
	    }
	    if ($echo) {
	    	// 调试环境这个函数使用
	        if(SW_DEBUG) @$this->response->write($output);
	        return null;
	    }else
	        return $output;
	}
	/**
	 * sendHttpStatus,参考tp的
	 * @param    {String}
	 * @param    [type]        $code [description]
	 * @return   [type]              [description]
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