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

class MGeneral extends \Swoolefy\Core\AppObject {
	/**
	 * isSsl
	 * @return   boolean
	 */
	public static function isSsl() {
        $request = Application::getApp()->request;

	    if(isset($request->server['HTTPS']) && ('1' == $request->server['HTTPS'] || 'on' == strtolower($request->server['HTTPS']))){
	        return true;
	    }elseif(isset($request->server['SERVER_PORT']) && ('443' == $request->server['SERVER_PORT'] )) {
	        return true;
	    }
	    return false;
	}

	/**
	 * isMobile 
	 * @return   boolean
	 */
    public static function isMobile() {
        $request = Application::getApp()->request;

        if (isset($request->server['HTTP_VIA']) && stristr($request->server['HTTP_VIA'], "wap")) {
            return true;
        } elseif (isset($request->server['HTTP_ACCEPT']) && strpos(strtoupper($request->server['HTTP_ACCEPT']), "VND.WAP.WML")) {
            return true;
        } elseif (isset($request->server['HTTP_X_WAP_PROFILE']) || isset($request->server['HTTP_PROFILE'])) {
            return true;
        } elseif (isset($request->server['HTTP_USER_AGENT']) && preg_match('/(blackberry|configuration\/cldc|hp |hp-|htc |htc_|htc-|iemobile|kindle|midp|mmp|motorola|mobile|nokia|opera mini|opera |Googlebot-Mobile|YahooSeeker\/M1A1-R2D2|android|iphone|ipod|mobi|palm|palmos|pocket|portalmmm|ppc;|smartphone|sonyericsson|sqh|spv|symbian|treo|up.browser|up.link|vodafone|windows ce|xda |xda_)/i', $request->server['HTTP_USER_AGENT'])) {
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
	 * @param   int  $type 返回类型 0:返回IP地址,1:返回IPV4地址数字
	 * @return  string
	 */
	public static function getClientIP($type=0) {
        $request = Application::getApp()->request;
        
		// 通过nginx的代理
		if(isset($request->server['HTTP_X_REAL_IP']) && strcasecmp($request->server['HTTP_X_REAL_IP'], "unknown")) {
			$ip = $request->server['HTTP_X_REAL_IP'];
		}
		if(isset($request->server['HTTP_CLIENT_IP']) && strcasecmp($request->server['HTTP_CLIENT_IP'], "unknown")) {
	    	$ip = $request->server["HTTP_CLIENT_IP"];
	    }
	    if (isset($request->server['HTTP_X_FORWARDED_FOR']) and strcasecmp($request->server['HTTP_X_FORWARDED_FOR'], "unknown"))
        {
            return $request->server['HTTP_X_FORWARDED_FOR'];
        }
	    if(isset($request->server['REMOTE_ADDR'])) {
	    	//没通过代理，或者通过代理而没设置x-real-ip的 
	    	$ip = $request->server['REMOTE_ADDR'];
	    }
	    // IP地址合法验证 
	    $long = sprintf("%u", ip2long($ip));
        $ip   = $long ? [$ip, $long] : ['0.0.0.0', 0];
        return $ip[$type];
	}

	/**
	 * isValidateEmail 判断是否是合法的邮箱
	 * @param    string  $email 
	 * @return   boolean
	 */
	public static function isValidateEmail($email) {
		return filter_var($email, FILTER_VALIDATE_EMAIL);
	}

    /**
     * isValidateIp 判断是否是合法的的ip地址
     * @param    string  $ip
     * @return   boolean
     */
    public static function isValidateIp($ip) {
        $ipv4 = ip2long($ip);
        if(is_numeric($ipv4)) {
            return true;
        }
        return false;
    }

	/**
	 * roundByPrecision 四舍五入
	 * @param    float  $number    数值
	 * @param    int    $precision 精度
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
     * _die 异常终端程序执行
     * @param    string   $msg
     * @param    int      $code
     * @return   mixed
     */
    public static function _die($html='',$msg='') {
        // 直接结束请求
        Application::getApp()->response->end($html);
        throw new \Exception($msg);
    }

    /**
     * xhprof 性能分析函数
     * @param   boolean  $force  是否强制输出
     * @return  boolean
     */
    public static function xhprof($force=false) {
        $host = Application::getApp()->request->server['HTTP_HOST'];

        if(SW_DEBUG) {
            $xhprof_data = xhprof_disable();
            include_once ROOT_PATH.'/xhprof/xhprof_lib/utils/xhprof_lib.php';
            include_once ROOT_PATH.'/xhprof/xhprof_lib/utils/xhprof_runs.php';
            $xhprof_runs = new \XHProfRuns_Default();
            $run_id = $xhprof_runs->save_run($xhprof_data, "xhprof_test");
            $url= "http://{$host}/xhprof_html/index.php?run={$run_id}&source=xhprof_test";
            dump($url);
        }elseif($force) {
            // 是否强制打印调试,在预发布和正式环境
            $xhprof_data = xhprof_disable();
            include_once ROOT_PATH.'/xhprof_lib/utils/xhprof_lib.php';
            include_once ROOT_PATH.'/xhprof_lib/utils/xhprof_runs.php';
            $xhprof_runs = new \XHProfRuns_Default();
            $run_id = $xhprof_runs->save_run($xhprof_data, "xhprof_test");
            $url= "http://{$host}/xhprof_html/index.php?run={$run_id}&source=xhprof_test";
            dump($url);
        }
        return false; 
    }

    /**
     * string 随机生成一个字符串
     * @param   int  $length
     * @param   bool  $number 只添加数字
     * @param   array  $ignore 忽略某些字符串
     * @return string
     */
    public static function string($length = 8, $number = true, $ignore = []) {
        //字符池
        $strings = 'ABCDEFGHIJKLOMNOPQRSTUVWXYZ';
        //数字池
        $numbers = '0123456789';           
        if ($ignore && is_array($ignore)) {
            $strings = str_replace($ignore, '', $strings);
            $numbers = str_replace($ignore, '', $numbers);
        }
        $pattern = $strings . $numbers;
        $max = strlen($pattern) - 1;
        $key = '';
        for ($i = 0; $i < $length; $i++)
        {   
                //生成php随机数
            $key .= $pattern[mt_rand(0, $max)]; 
        }
        return $key;
    }

    /**
     * idhash 按id计算散列值
     * @param   int  $uid
     * @param   int  $base
     * @return integer
     */
    public static function idhash($uid, $base = 100) {
        return intval($uid / $base);
    }

    /**
     * randtime 按UNIX时间戳产生随机数
     * @param   int  $rand_length
     * @return string
     */
    public static function randtime($rand_length = 6) {
        list($usec, $sec) = explode(" ", microtime());
        $min = intval('1' . str_repeat('0', $rand_length - 1));
        $max = intval(str_repeat('9', $rand_length));
        return substr($sec, -5) . ((int)$usec * 100) . rand($min, $max);
    }

    /**
     * randmd5 产生一个随机MD5字符的一部分
     * @param   int  $length
     * @param   int  $seed
     * @return string
     */
    public static function randmd5($length = 20, $seed = null) {
        if (empty($seed)) {
            $seed = self::string(20);
        }
        return substr(md5($seed . rand(111111, 999999)), 0, $length);
    }

    /**
     * mbstrlen 计算某个混合字符串的长度总数，包含英文或者中文的字符串,如果安装mb_string扩展的话，可以直接使用mb_strlen()函数，与该函数等效
     * @param    string  $str 
     * @return   int
     */
    public static function mbStrlen($str) {
        // strlen()计算的是字节
        $len = strlen($str);
        if ($len <= 0) {
            return 0;
        }
        
        $count = 0;
        for($i = 0; $i < $len; $i++) {
            $count++;
            if(ord($str{$i}) >= 0x80) {
                $i += 2;
            }
        }
        return $count;
    }

	/**
	 * getBrowser 获取浏览器
	 * @return   string
	 */
	public static function getBrowser() {
        $sys = Application::getApp()->request->server['HTTP_USER_AGENT'];

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
    public static function getClientOS() {
        $agent = Application::getApp()->request->server['HTTP_USER_AGENT'];

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