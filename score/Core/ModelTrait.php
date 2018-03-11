<?php
namespace Swoolefy\Core;

trait ModelTrait {
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
        
        }elseif(isset($this->request->server['HTTP_ACCEPT']) && strpos(strtoupper($this->request->server['HTTP_ACCEPT']), "VND.WAP.WML")) {
            return true;
        
        }elseif(isset($this->request->server['HTTP_X_WAP_PROFILE']) || isset($this->request->server['HTTP_PROFILE'])) {
            return true;
        
        }elseif(isset($this->request->server['HTTP_USER_AGENT']) && preg_match('/(blackberry|configuration\/cldc|hp |hp-|htc |htc_|htc-|iemobile|kindle|midp|mmp|motorola|mobile|nokia|opera mini|opera |Googlebot-Mobile|YahooSeeker\/M1A1-R2D2|android|iphone|ipod|mobi|palm|palmos|pocket|portalmmm|ppc;|smartphone|sonyericsson|sqh|spv|symbian|treo|up.browser|up.link|vodafone|windows ce|xda |xda_)/i', $this->request->server['HTTP_USER_AGENT'])) {
            return true;
        
        }else {
            return false;
        }
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
}