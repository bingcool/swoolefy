<?php
namespace Swoolefy\Core;

class MGeneral extends \Swoolefy\Core\Object {
	/**
	 * isSsl
	 * @return   boolean
	 */
	public static function isSsl() {
	    if(isset($_SERVER['HTTPS']) && ('1' == $_SERVER['HTTPS'] || 'on' == strtolower($_SERVER['HTTPS']))){
	        return true;
	    }elseif(isset($_SERVER['SERVER_PORT']) && ('443' == $_SERVER['SERVER_PORT'] )) {
	        return true;
	    }
	    return false;
	}

	/**
	 * isMobile 
	 * @return   boolean
	 */
    public static function isMobile() {
        if (isset($_SERVER['HTTP_VIA']) && stristr($_SERVER['HTTP_VIA'], "wap")) {
            return true;
        } elseif (isset($_SERVER['HTTP_ACCEPT']) && strpos(strtoupper($_SERVER['HTTP_ACCEPT']), "VND.WAP.WML")) {
            return true;
        } elseif (isset($_SERVER['HTTP_X_WAP_PROFILE']) || isset($_SERVER['HTTP_PROFILE'])) {
            return true;
        } elseif (isset($_SERVER['HTTP_USER_AGENT']) && preg_match('/(blackberry|configuration\/cldc|hp |hp-|htc |htc_|htc-|iemobile|kindle|midp|mmp|motorola|mobile|nokia|opera mini|opera |Googlebot-Mobile|YahooSeeker\/M1A1-R2D2|android|iphone|ipod|mobi|palm|palmos|pocket|portalmmm|ppc;|smartphone|sonyericsson|sqh|spv|symbian|treo|up.browser|up.link|vodafone|windows ce|xda |xda_)/i', $_SERVER['HTTP_USER_AGENT'])) {
            return true;
        } else {
            return false;
        }
    }

    /**
	 * getLocalIp 获取ip,不包括端口
	 * @return   array
	 */
	public static function getLocalIp() {
		return swoole_get_local_ip();
	}

	/**
	 * getClientIP 获取客户端ip
	 * @param   $type 返回类型 0:返回IP地址,1:返回IPV4地址数字
	 * @return  string
	 */
	public static function getClientIP($type=0) {
		// 通过nginx的代理
		if(isset($_SERVER['HTTP_X_REAL_IP']) && strcasecmp($_SERVER['HTTP_X_REAL_IP'], "unknown")) {
			$ip = $_SERVER['HTTP_X_REAL_IP'];
		}
		if(isset($_SERVER['HTTP_CLIENT_IP']) && strcasecmp($_SERVER['HTTP_CLIENT_IP'], "unknown")) {
	    	$ip = $_SERVER["HTTP_CLIENT_IP"];
	    }
	    if (isset($_SERVER['HTTP_X_FORWARDED_FOR']) and strcasecmp($_SERVER['HTTP_X_FORWARDED_FOR'], "unknown"))
        {
            return $_SERVER['HTTP_X_FORWARDED_FOR'];
        }
	    if(isset($_SERVER['REMOTE_ADDR'])) {
	    	//没通过代理，或者通过代理而没设置x-real-ip的 
	    	$ip = $_SERVER['REMOTE_ADDR'];
	    }
	    // IP地址合法验证 
	    $long = sprintf("%u", ip2long($ip));
        $ip   = $long ? [$ip, $long] : ['0.0.0.0', 0];
        return $ip[$type];
	}

	/**
	 * isValidateEmail 判断是否是合法的邮箱
	 * @param    $email 
	 * @return   boolean
	 */
	public static function isValidateEmail($email) {
		return filter_var($email, FILTER_VALIDATE_EMAIL);
	}

	/**
	 * roundByPrecision 四舍五入
	 * @param    $number    数值
	 * @param    $precision 精度
	 * @return   float
	 */
	public static function roundByPrecision($number, $precision) {
		if (strpos($number, '.') && (strlen(substr($number, strpos($number, '.')+1)) > $precision))
		{
			$number = substr($number, 0, strpos($number, '.') + 1 + $precision + 1);
			if (substr($number, -1) >= 5)
			{
				if ($precision > 1)
				{
					$number = substr($number, 0, -1) + ('0.' . str_repeat(0, $precision-1) . '1');
				}
				elseif ($precision == 1)
				{
					$number = substr($number, 0, -1) + 0.1;
				}
				else
				{
					$number = substr($number, 0, -1) + 1;
				}
			}
			else
			{
				$number = substr($number, 0, -1);
			}
		}
		return $number;
	}

	/**
	 * getBrowser 获取浏览器
	 * @return   string
	 */
	public function getBrowser() {
        $sys = $_SERVER['HTTP_USER_AGENT'];
        if (stripos($sys, "Firefox/") > 0)
 		{
            preg_match("/Firefox\/([^;)]+)+/i", $sys, $b);
            $exp[0] = "Firefox";
            $exp[1] = $b[1];
        }
        elseif (stripos($sys, "Chrome") > 0)
        {
            preg_match("/Chrome\/([\d\.]+)/", $sys, $google);
            $exp[0] = "Chrome";
            $exp[1] = $google[1];
        }
        elseif (stripos($sys, "Edge") > 0)
        {
            preg_match("/Edge\/([\d\.]+)/", $sys, $Edge);
            $exp[0] = "Edge";
            $exp[1] = $Edge[1];
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
        $agent = $_SERVER['HTTP_USER_AGENT'];
        if (preg_match('/win/i', $agent) && preg_match('/nt 6.1/i', $agent))
        {
            $clientOS = 'Windows 7';
        }
        elseif (preg_match('/win/i', $agent) && preg_match('/nt 10.0/i', $agent))
        {
            $clientOS = 'Windows 10';
        }
        elseif (preg_match('/win/i', $agent) && preg_match('/nt 6.2/i', $agent))
        {
            $clientOS = 'Windows 8';
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
        elseif (preg_match('/win/i', $agent) && preg_match('/nt 5.1/i', $agent))
        {
            $clientOS = 'Windows XP';
        }
        elseif(preg_match('/win/i', $agent) && strpos($agent, '95'))
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