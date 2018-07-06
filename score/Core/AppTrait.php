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

trait AppTrait {
	/**
	 * $previousUrl,记录url
	 * @var array
	 */
	public $previousUrl = [];

	/**
	 * $selfModel 控制器对应的自身model
	 * @var array
	 */
	public $selfModel = [];
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
		return ($this->request->server['REQUEST_METHOD'] == 'GET') ? true :false;
	}

	/**
	 * isPost
	 * @return boolean
	 */
	public function isPost() {
		return ($this->request->server['REQUEST_METHOD'] == 'POST') ? true :false;
	}

	/**
	 * isPut
	 * @return boolean
	 */
	public function isPut() {
		return ($this->request->server['REQUEST_METHOD'] == 'PUT') ? true :false;
	}

	/**
	 * isDelete
	 * @return boolean
	 */
	public function isDelete() {
		return ($this->request->server['REQUEST_METHOD'] == 'DELETE') ? true :false;
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
	    if(isset($this->request->server['HTTPS']) && ('1' == $this->request->server['HTTPS'] || 'on' == strtolower($this->request->server['HTTPS']))){
	        return true;
	    }elseif(isset($this->request->server['SERVER_PORT']) && ('443' == $this->request->server['SERVER_PORT'] )) {
	        return true;
	    }
	    return false;
	}

	/**
	 * isMobile 
	 * @return   boolean
	 */
    public function isMobile() {
        if (isset($this->request->server['HTTP_VIA']) && stristr($this->request->server['HTTP_VIA'], "wap")) {
            return true;
        } elseif (isset($this->request->server['HTTP_ACCEPT']) && strpos(strtoupper($this->request->server['HTTP_ACCEPT']), "VND.WAP.WML")) {
            return true;
        } elseif (isset($this->request->server['HTTP_X_WAP_PROFILE']) || isset($this->request->server['HTTP_PROFILE'])) {
            return true;
        } elseif (isset($this->request->server['HTTP_USER_AGENT']) && preg_match('/(blackberry|configuration\/cldc|hp |hp-|htc |htc_|htc-|iemobile|kindle|midp|mmp|motorola|mobile|nokia|opera mini|opera |Googlebot-Mobile|YahooSeeker\/M1A1-R2D2|android|iphone|ipod|mobi|palm|palmos|pocket|portalmmm|ppc;|smartphone|sonyericsson|sqh|spv|symbian|treo|up.browser|up.link|vodafone|windows ce|xda |xda_)/i', $this->request->server['HTTP_USER_AGENT'])) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * getRequest 
     * @return request 对象
     */
    public function getRequest() {
    	return $this->request;
    }

    /**
     * getResponse 
     * @return response 对象
     */
    public function getResponse() {
    	return $this->response;
    }

    /**
     * getRequestParam 
     * @param    string   $key
     * @param    string   $mothod
     * @return   mxixed
     */
    public function getRequestParam(string $name = null) {
    	$mothod = strtolower($this->getMethod());
    	switch($mothod) {
    		case 'get' : 
    			$input = $this->request->get;
    		break;

    		case 'post':
    			$input = $this->request->post;
    		break; 
    		default :
    			$input = [];
    		break;
    	}
    	if($name) {
    		$value = (isset($input[$name]) && !empty($input[$name])) ? $input[$name] : null;
    	}else {
    		$get = isset($this->request->get) ? $this->request->get : [];
    		$post = isset($this->request->post) ? $this->request->post : [];
    		$value = array_merge($get, $post);
    		$value = $value ? $value : null;
    		unset($input, $get, $post);
    	}
    	return $value;
    }

    /**
     * getCookieParam 
     * @param    string|null   $name
     * @return   mixed
     */
    public function getCookieParam(string $name = null) {
    	if($name) {
    		$value = isset($this->request->cookie[$name]) ? $this->request->cookie[$name] : null;
    		return $value;	
    	}

    	return $this->request->cookie;
    }

    /**
     * getServerParam 
     * @param    string|null   $name
     * @return   mixed
     */
    public function getServerParam(string $name = null) {
    	if($name) {
    		$value = isset($this->request->server[$name]) ? $this->request->server[$name] : null;
    		return $value;	
    	}

    	return $this->request->server;
    }

    /**
     * getHeaderParam 
     * @param    string|null   $name
     * @return   mixed
     */
    public function getHeaderParam(string $name = null) {
    	if($name) {
    		$value = isset($this->request->header[$name]) ? $this->request->header[$name] : null;
    		return $value;
    	}

    	return $this->request->header;
    }

    /**
     * getFilesParam 
     * @return   mixed
     */
    public function getUploadFiles() {
    	return $this->request->files;
    }

    /**
     * getRawContent 
     * @return  mixed
     */
    public function getRawContent() {
    	return $this->request->rawContent();
    }

	/**
	 * getMethod 
	 * @return   string
	 */
	public function getMethod() {
		return $this->request->server['REQUEST_METHOD'];
	}

	/**
	 * getRequestUri
	 * @return string
	 */
	public function getRequestUri() {
		return $this->request->server['PATH_INFO'];
	}

	/**
	 * getRoute
	 * @return  string
	 */
	public function getRoute() {
		return $this->request->server['ROUTE'];
	}

	/**
	 * getQueryString
	 * @return   string
	 */
	public function getQueryString() {
		return $this->request->server['QUERY_STRING'];
	}

	/**
	 * getProtocol
	 * @return   string
	 */
	public function getProtocol() {
		return $this->request->server['SERVER_PROTOCOL'];
	}

	/**
	 * getHomeUrl 获取当前请求的url
	 * @param    $ssl
	 * @return   string
	 */
	public function getHomeUrl(bool $ssl=false) {
		$protocol_version = $this->getProtocol();
		list($protocol, $version) = explode('/', $protocol_version);
		
		$protocol = strtolower($protocol).'://';

		if($ssl) {
			$protocol = 'https://';
		}
		return $protocol.$this->getHostName().$this->getRequestUri().'?'.$this->getQueryString();
	}

	/**
	 * rememberUrl
	 * @param  string  $name
	 * @param  string  $url
	 * @param  boolean $ssl
	 * @return   void   
	 */
	public function rememberUrl(string $name = null, string $url=null, bool $ssl=false) {
		if($url && $name) {
			$this->previousUrl[$name] = $url;
		}else {
			// 获取当前的url保存
			$this->previousUrl['home_url'] = $this->getHomeUrl($ssl);
		}
	}

	/**
	 * getPreviousUrl
	 * @param  string  $name
	 * @return   mixed
	 */
	public function getPreviousUrl(string $name = null) {
		if($name) {
			if(isset($this->previousUrl[$name])) {
				return $this->previousUrl[$name];
			}
			return null;
		}else {
			if(isset($this->previousUrl['home_url'])) {
				return $this->previousUrl['home_url'];
			}

			return null;
		}
	} 

	/**
	 * getRoute
	 * @return array
	 */
	public function getRouteParams() {
		return $this->request->server['ROUTE_PARAMS'];
	}

	/**
	 * getModule 
	 * @return string|null
	 */
	public function getModule() {
		list($count,$routeParams) = $this->getRouteParams();
		if($count == 3) {
			return $routeParams[0];
		}else {
			return null;
		}
	}

	/**
	 * getController
	 * @return string
	 */
	public function getController() {
		list($count,$routeParams) = $this->getRouteParams();
		if($count == 3) {
			return $routeParams[1];
		}else {
			return $routeParams[0];
		}
	}

	/**
	 * getAction
	 * @return string
	 */
	public function getAction() {
		$routeParams = $this->getRouteParams();
		return array_pop($routeParams);
	}

	/**
	 * getModel 默认获取当前module下的控制器对应的module
	 * @param  string  $model
	 * @return object
	 */
	public function getModel(string $model = '', string $module = '') {
		if(empty($module)) {
			$module = $this->getModule();
		}
		$controller = $this->getController();
		// 如果存在module
		if(!empty($module)) {
			// model的类文件对应控制器
			if(!empty($model)) {
				$modelClass = $this->config['app_namespace'].'\\'.'Module'.'\\'.$module.'\\'.'Model'.'\\'.$model;
			}else {
				$modelClass = $this->config['app_namespace'].'\\'.'Module'.'\\'.$module.'\\'.'Model'.'\\'.$controller;
			}
		}else {
			// model的类文件对应控制器
			if(!empty($model)) {
				$modelClass = $this->config['app_namespace'].'\\'.'Model'.'\\'.$model;
				
			}else {
				$modelClass = $this->config['app_namespace'].'\\'.'Model'.'\\'.$controller;
			}
		}
		// 从内存数组中返回
		if(isset($this->selfModel[$modelClass])) {
			return $this->selfModel[$modelClass];
		}else {
			try{
				$modelInstance = new $modelClass;
				return $this->selfModel[$modelClass] = $modelInstance;
			}catch(\Exception $e) {
				throw new \Exception($e->getMessage(), 1);
			}
		}

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
		return Application::getApp()->view;
	}


	/**
	 * assign
	 * @param   string  $name
	 * @param   string|array  $value
	 * @return  void   
	 */
	public function assign(string $name, $value) {
		Application::getApp()->view->assign($name,$value);
	}

	/**
	 * display
	 * @param    string  $template_file
	 * @return   void             
	 */
	public function display(string $template_file = null) {
		Application::getApp()->view->display($template_file);
	}

	/**
	 * fetch
	 * @param    string  $template_file
	 * @return   void              
	 */
	public function fetch(string $template_file = null) {
		Application::getApp()->view->display($template_file);
	}

	/**
	 * returnJson
	 * @param    array  $data    
	 * @param    string  $formater
	 * @return   void         
	 */
	public function returnJson(array $data, string $formater = 'json') {
		Application::getApp()->view->returnJson($data, $formater);
	}

	/**
	 * sendfile
	 * @param    string  $filename 
	 * @param    int     $offset   
	 * @param    string  $length   
	 * @return   void          
	 */
	public function sendfile(string $filename, int $offset = 0, int $length = 0) {
		$this->response->sendfile($filename, $offset = 0, $length = 0);
	}

	/**
	 * parseUri 解析URI
	 * @param    string  $url
	 * @return   array
	 */
	public function parseUri(string $url) {
        $res = parse_url($url);
        $return['protocol'] = $res['scheme'];
        $return['host'] = $res['host'];
        $return['port'] = $res['port'];
        $return['user'] = $res['user'];
        $return['pass'] = $res['pass'];
        $return['path'] = $res['path'];
        $return['id'] = $res['fragment'];
        parse_str($res['query'], $return['params']);
        return $return;
    }

	/**
	 * redirect 重定向,使用这个函数后,要return,停止程序执行
	 * @param    string  $url
	 * @param    array   $params eg:['name'=>'ming','age'=>18]
	 * @param    int     $code default 301
	 * @return   void
	 */
	public function redirect(string $url, array $params = [], int $code=301) {
		$query_string = '';
		trim($url);
		if(strpos($url, 'http') === false || strpos($url, 'https') === false) {
			if(strpos($url, '/') != 0) {
				$url = '/'.$url;
			}
		}
		
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
		return;
	}

	/**
	 * dump，调试函数
	 * @param    string|array  $var
	 * @param    boolean       $echo
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
	 * asyncHttpClient 简单的模拟http异步并发请求
	 * @param    array   $urls 
	 * @param    int     $timeout 单位ms
	 * @return   
	 */
	public function asyncHttpClient(array $urls = [], int $timeout = 500) {
		if(!empty($urls)) {
			$conn = [];
			$mh = curl_multi_init();
			foreach($urls as $i => $url) {
				$conn[$i] = curl_init($url);
					curl_setopt($conn[$i], CURLOPT_CUSTOMREQUEST, "GET");
				  	curl_setopt($conn[$i], CURLOPT_HEADER ,0);
				  	curl_setopt($conn[$i], CURLOPT_SSL_VERIFYPEER, FALSE);
					curl_setopt($conn[$i], CURLOPT_SSL_VERIFYHOST, FALSE);
					curl_setopt($conn[$i], CURLOPT_NOSIGNAL, 1);
					curl_setopt($conn[$i], CURLOPT_TIMEOUT_MS,$timeout);   
				  	curl_setopt($conn[$i],CURLOPT_RETURNTRANSFER,true);
				  	curl_multi_add_handle($mh,$conn[$i]);
			}

			do {   
  				curl_multi_exec($mh,$active);   
			}while ($active);

			foreach ($urls as $i => $url) {   
  				curl_multi_remove_handle($mh,$conn[$i]);   
  				curl_close($conn[$i]);   
			}
			curl_multi_close($mh);
			return true;
		}
		return false;
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
     * getClientIP 获取客户端ip
     * @param   int  $type 返回类型 0:返回IP地址,1:返回IPV4地址数字
     * @return  mixed
     */
    public function getClientIP(int $type = 0) {
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
     * header,使用链式作用域
     * @param    string  $name
     * @param    string  $value
     * @return   object
     */
    public function header(string $name, $value) {
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
    public function setCookie(
    	$key, 
    	$value = '', 
    	$expire = 0, 
    	$path = '/', 
    	$domain = '', 
    	$secure = false,
    	$httponly = false
    ) {
        $this->response->cookie($key, $value, $expire, $path, $domain, $secure, $httponly);
        return $this->response;
    }

    /**
	 * getHostName
	 * @return   string
	 */
	public function getHostName() {
		return $this->request->server['HTTP_HOST'];
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

	/**
	 * sendHttpStatus,参考tp的
	 * @param    int  $code
	 * @return   void     
	 */
	public function status(int $code) {
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