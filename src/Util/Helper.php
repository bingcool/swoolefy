<?php
/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | @see https://github.com/bingcool/swoolefy
 * +----------------------------------------------------------------------
 */

namespace Swoolefy\Util;

class Helper
{

    /**
     * isValidateEmail 判断是否是合法的邮箱
     * @param string $email
     * @return bool
     */
    public static function isValidateEmail(string $email)
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL);
    }

    /**
     * isValidateIp 判断是否是合法的的ip地址
     * @param string $ip
     * @return bool
     */
    public static function isValidateIp(string $ip)
    {
        $ipv4 = ip2long($ip);
        if (is_numeric($ipv4)) {
            return true;
        }
        return false;
    }

    /**
     * getLocalIp
     * @return array
     */
    public static function getLocalIp()
    {
        return swoole_get_local_ip();
    }

    /**
     * ip是否是公网IP
     * @param $ip
     * @return bool|mixed
     */
    public static function isInternalIp($ip)
    {
        $ip = ip2long($ip);
        if (!$ip) {
            return false;
        }
        $result = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        if ($result) {
            $result = true;
        }
        return $result;
    }

    /**
     * 入库过滤
     * @param $value
     * @return string
     */
    public static function escapeDoubleQuoteSql($value)
    {
        return addcslashes(str_replace("'", "''", $value), "\000\n\r\\\032");
    }

    /**
     * randmd5 产生一个随机MD5字符的一部分
     * @param int $length
     * @param int $seed
     * @return  string
     */
    public static function randMd5(int $length = 20, $seed = null)
    {
        if (empty($seed)) {
            $seed = self::randString(20);
        }
        return substr(md5($seed . mt_rand(111111, 999999) . bin2hex(random_bytes(5))), 0, $length);
    }

    /**
     * randString 随机生成一个字符串
     * @param int $length
     * @param bool $number 只添加数字
     * @param array $ignore 忽略某些字符串
     * @return string
     */
    public static function randString(int $length = 8, bool $is_number = false, array $ignore = [])
    {
        //字符池
        $strings = 'ABCDEFGHIJKLOMNOPQRSTUVWXYZ_abcdefghijklomnopqrstuvwxyz-';
        //数字池
        $numbers = '0123456789';
        if ($ignore && is_array($ignore)) {
            $strings = str_replace($ignore, '', $strings);
            $numbers = str_replace($ignore, '', $numbers);
        }
        if ($is_number) {
            $pattern = $numbers;
        } else {
            $pattern = $strings . $numbers;
        }
        $max = strlen($pattern) - 1;
        $key = '';
        for ($i = 0; $i < $length; $i++) {
            //生成php随机数
            $key .= $pattern[mt_rand(0, $max)];
        }
        return $key;
    }

    /**
     * idHash 按id计算散列值
     * @param int $uid
     * @param int $base
     * @return int
     */
    public static function idHash(int $uid, int $base = 100)
    {
        return intval($uid / $base);
    }

    /**
     * randTime 按UNIX时间戳产生随机数
     * @param int $rand_length
     * @return  string
     */
    public static function randTime(int $rand_length = 6)
    {
        list($usec, $sec) = explode(" ", microtime());
        $min = intval('1' . str_repeat('0', $rand_length - 1));
        $max = intval(str_repeat('9', $rand_length));
        return substr($sec, -5) . ((int)$usec * 100) . mt_rand($min, $max);
    }

    /**
     * @param string $url the URL to be checked
     * @return bool whether the URL is relative
     */
    public static function isRelative(string $url)
    {
        return strncmp($url, '//', 2) && strpos($url, '://') === false;
    }

    /**
     * mbStrlen 计算某个混合字符串的长度总数，包含英文或者中文的字符串,如果安装mb_string扩展的话，可以直接使用mb_strlen()函数，与该函数等效
     * @param string $str
     * @return int
     */
    public static function mbStrlen(string $str)
    {
        // strlen()计算的是字节
        $len = strlen($str);
        if ($len <= 0) {
            return 0;
        }
        $count = 0;
        for ($i = 0; $i < $len; $i++) {
            $count++;
            if (ord($str{$i}) >= 0x80) {
                $i += 2;
            }
        }
        return $count;
    }

    /**
     * roundByPrecision 四舍五入
     * @param float $number 数值
     * @param int $precision 精度
     * @return float
     */
    public static function roundByPrecision(float $number, int $precision)
    {
        if (strpos($number, '.') && (strlen(substr($number, strpos($number, '.') + 1)) > $precision)) {
            $number = substr($number, 0, strpos($number, '.') + 1 + $precision + 1);
            if (substr($number, -1) >= 5) {
                if ($precision > 1) {
                    $number = substr($number, 0, -1) + (float)('0.' . str_repeat(0, $precision - 1) . '1');
                } elseif ($precision == 1) {
                    $number = substr($number, 0, -1) + 0.1;
                } else {
                    $number = substr($number, 0, -1) + 1;
                }
            } else {
                $number = substr($number, 0, -1);
            }
        }
        return $number;
    }

    /**
     * 检查字符串中是否包含某些字符串
     * @param string $haystack
     * @param string|array $needles
     * @return bool
     */
    public static function contains(string $haystack, $needles)
    {
        foreach ((array)$needles as $needle) {
            if ('' != $needle && mb_strpos($haystack, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * 检查字符串是否以某些字符串结尾
     * @param string $haystack
     * @param string|array $needles
     * @return bool
     */
    public static function endsWith(string $haystack, $needles)
    {
        foreach ((array)$needles as $needle) {
            if ((string)$needle === static::substr($haystack, -static::length($needle))) {
                return true;
            }
        }
        return false;
    }

    /**
     * 检查字符串是否以某些字符串开头
     * @param string $haystack
     * @param string|array $needles
     * @return bool
     */
    public static function startsWith(string $haystack, $needles)
    {
        foreach ((array)$needles as $needle) {
            if ('' != $needle && mb_strpos($haystack, $needle) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * 字符串转小写
     * @param string $value
     * @return string
     */
    public static function lower(string $value)
    {
        return mb_strtolower($value, 'UTF-8');
    }

    /**
     * 字符串转大写
     * @param string $value
     * @return string
     */
    public static function upper(string $value)
    {
        return mb_strtoupper($value, 'UTF-8');
    }

    /**
     * 获取字符串的长度
     * @param string $value
     * @return int
     */
    public static function length(string $value)
    {
        return mb_strlen($value);
    }

    /**
     * 截取字符串
     *
     * @param string $string
     * @param int $start
     * @param int|null $length
     * @return string
     */
    public static function substr(string $string, int $start, int $length = null): string
    {
        return mb_substr($string, $start, $length, 'UTF-8');
    }

    /**
     * 驼峰转下划线
     * @param string $value
     * @param string $delimiter
     * @return string
     */
    public static function snake(string $value, string $delimiter = '_')
    {
        if (!ctype_lower($value)) {
            $value = preg_replace('/\s+/u', '', $value);
            $value = static::lower(preg_replace('/(.)(?=[A-Z])/u', '$1' . $delimiter, $value));
        }
        return $value;
    }

    /**
     * 下划线转驼峰(首字母小写)
     * @param string $value
     * @return string
     */
    public static function camel(string $value)
    {

        return lcfirst(static::studly($value));
    }

    /**
     * 下划线转驼峰(首字母大写)
     * @param string $value
     * @return string
     */
    public static function studly(string $value)
    {
        $value = ucwords(str_replace(['-', '_'], ' ', $value));
        return str_replace(' ', '', $value);
    }

    /**
     * 转为首字母大写的标题格式
     * @param string $value
     * @return string
     */
    public static function title(string $value)
    {
        return mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * asyncHttpClient 简单的模拟http异步并发请求
     * @param array $urls
     * @param int $timeout 单位ms
     * @return bool
     */
    public function asyncHttpClient(array $urls = [], int $timeout = 500)
    {
        if (!empty($urls)) {
            $conn = [];
            $mh = curl_multi_init();
            foreach ($urls as $i => $url) {
                $conn[$i] = curl_init($url);
                curl_setopt($conn[$i], CURLOPT_CUSTOMREQUEST, "GET");
                curl_setopt($conn[$i], CURLOPT_HEADER, 0);
                curl_setopt($conn[$i], CURLOPT_SSL_VERIFYPEER, FALSE);
                curl_setopt($conn[$i], CURLOPT_SSL_VERIFYHOST, FALSE);
                curl_setopt($conn[$i], CURLOPT_NOSIGNAL, 1);
                curl_setopt($conn[$i], CURLOPT_TIMEOUT_MS, $timeout);
                curl_setopt($conn[$i], CURLOPT_RETURNTRANSFER, true);
                curl_multi_add_handle($mh, $conn[$i]);
            }

            do {
                curl_multi_exec($mh, $active);
            } while ($active);

            foreach ($urls as $i => $url) {
                curl_multi_remove_handle($mh, $conn[$i]);
                curl_close($conn[$i]);
            }
            curl_multi_close($mh);
            return true;
        }
        return false;
    }
}