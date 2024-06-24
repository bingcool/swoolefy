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

namespace Swoolefy\Http;

use Swoolefy\Core\Application;
use Swoolefy\Core\SystemEnv;

class ResponseOutput extends HttpInputOut
{
    use ResponseParseTrait;

    /**
     * redirect 重定向-使用这个函数后,要return,停止程序执行
     * @param string $url
     * @param array $params eg:['name'=>'ming','age'=>18]
     * @param int $httpStatus default 301
     * @return void
     */
    public function redirect(string $url, array $params = [], int $httpStatus = 301)
    {
        $queryString = '';
        $url = trim($url);
        if (strncmp($url, '//', 2) && strpos($url, '://') === false) {
            if (strpos($url, '/') != 0) {
                $url = '/' . $url;
            }
            list($protocol, $version) = explode('/', $this->getProtocol());
            $protocol = strtolower($protocol) . '://';
            $url = $protocol . $this->getHostName() . $url;
        }
        if ($params) {
            if (strpos($url, '?') > 0) {
                $queryString = http_build_query($params);
            } else {
                $queryString = '?';
                $queryString .= http_build_query($params);
            }
        }
        if (is_object(Application::getApp())) {
            Application::getApp()->setEnd();
        }
        $url = $url . $queryString;
        $this->withStatus($httpStatus);
        $this->withHeader('Location', $url);
        $this->swooleResponse->end();
    }

    /**
     * dump 调试函数
     * @param mixed $var
     * @param bool $echo
     * @param mixed $label
     * @param bool $strict
     * @return string
     */
    public function dump($var, bool $echo = true, $label = null, bool $strict = true)
    {
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
            @ob_end_clean();
            if (!extension_loaded('xdebug')) {
                $output = preg_replace('/\]\=\>\n(\s+)/m', '] => ', $output);
                $output = '<pre>' . $label . htmlspecialchars($output, ENT_QUOTES) . '</pre>';
            }
        }
        if ($echo) {
            // 调试环境这个函数使用
            if (!SystemEnv::isPrdEnv()) @$this->swooleResponse->write($output);
            return null;
        } else {
            return $output;
        }
    }

    /**
     * getProtocol
     * @return string
     */
    public function getProtocol(): string
    {
        return $this->swooleRequest->server['SERVER_PROTOCOL'];
    }

    /**
     * getHostName
     * @return string
     */
    public function getHostName(): string
    {
        return $this->swooleRequest->server['HTTP_HOST'];
    }

    /**
     * header 使用链式作用域
     * @param string $name
     * @param string $value
     * @return ResponseOutput
     */
    public function withHeader(string $name, $value)
    {
        $this->swooleResponse->header($name, $value);
        return $this;
    }

    /**
     * withCookie 设置HTTP响应的cookie信息,PHP的setCookie()参数一致
     * @param string $key Cookie名称
     * @param string $value Cookie值
     * @param int $expire 有效时间
     * @param string $path 有效路径
     * @param string $domain 有效域名
     * @param bool $secure Cookie是否仅仅通过安全的HTTPS连接传给客户端
     * @param bool $httpOnly 设置成TRUE，Cookie仅可通过HTTP协议访问
     * @return ResponseOutput
     */
    public function withCookie(
        string $key,
        string $value = '',
        int    $expire = 0,
        string $path = '/',
        string $domain = '',
        bool   $secure = false,
        bool   $httpOnly = false
    )
    {
        $this->swooleResponse->cookie($key, $value, $expire, $path, $domain, $secure, $httpOnly);
        return $this;
    }

    /**
     * sendHttpStatus
     * @param int $code
     * @param string $reasonPhrase
     * @return ResponseOutput
     */
    public function withStatus(int $code, string $reasonPhrase = '')
    {
        $this->swooleResponse->status($code, $reasonPhrase);
        return $this;
    }
}