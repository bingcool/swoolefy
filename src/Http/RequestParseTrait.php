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

trait RequestParseTrait
{
    /**
     * $previousUrl
     * @var array
     */
    protected $previousUrl = [];

    /**
     * @var array
     */
    protected $requestParams = [];

    /**
     * @var array
     */
    protected $postParams = [];

    /**
     * @var array
     */
    protected $extendData = [];

    /**
     * isGet
     * @return bool
     */
    public function isGet(): bool
    {
        return (strtoupper($this->request->server['REQUEST_METHOD']) == 'GET') ? true : false;
    }

    /**
     * isPost
     * @return bool
     */
    public function isPost(): bool
    {
        return (strtoupper($this->request->server['REQUEST_METHOD']) == 'POST') ? true : false;
    }

    /**
     * isPut
     * @return bool
     */
    public function isPut(): bool
    {
        return (strtoupper($this->request->server['REQUEST_METHOD']) == 'PUT') ? true : false;
    }

    /**
     * isDelete
     * @return bool
     */
    public function isDelete(): bool
    {
        return (strtoupper($this->request->server['REQUEST_METHOD']) == 'DELETE') ? true : false;
    }

    /**
     * isAjax
     * @return bool
     */
    public function isAjax(): bool
    {
        return (isset($this->request->header['x-requested-with']) && strtolower($this->request->header['x-requested-with']) == 'xmlhttprequest') ? true : false;
    }

    /**
     * isSsl
     * @return bool
     */
    public function isSsl(): bool
    {
        if (isset($this->request->server['HTTPS']) && ('1' == $this->request->server['HTTPS'] || 'on' == strtolower($this->request->server['HTTPS']))) {
            return true;
        } elseif (isset($this->request->server['SERVER_PORT']) && ('443' == $this->request->server['SERVER_PORT'])) {
            return true;
        }
        return false;
    }

    /**
     * isMobile
     * @return bool
     */
    public function isMobile(): bool
    {
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
     * @return \Swoole\Http\Request
     */
    public function getRequest(): \Swoole\Http\Request
    {
        return $this->request;
    }

    /**
     * getResponse
     * @return \Swoole\Http\Response
     */
    public function getResponse(): \Swoole\Http\Response
    {
        return $this->response;
    }

    /**
     * getRequestParam  获取请求参数，包括get,post
     * @param string $name
     * @param mixed $default
     * @return mixed
     */
    public function getRequestParams(?string $name = null, $default = null): mixed
    {
        if (!$this->requestParams) {
            $get = isset($this->request->get) ? $this->request->get : [];
            $post = isset($this->request->post) ? $this->request->post : [];
            if (empty($post)) {
                $post = json_decode($this->request->rawContent(), true) ?? [];
            }
            $this->requestParams = array_merge($get, $post);
            unset($get, $post);
        }

        if ($name) {
            $value = $this->requestParams[$name] ?? $default;
        } else {
            $value = $this->requestParams;
        }
        return $value;
    }

    /**
     * @param string|null $name
     * @param $default
     * @return mixed
     */
    public function input(?string $name = null, $default = null)
    {
        return $this->getRequestParams($name, $default);
    }

    /**
     * getQueryParams 获取get参数
     * @param string|null $name
     * @param mixed $default
     * @return mixed
     */
    public function getQueryParams(?string $name = null, mixed $default = null): mixed
    {
        $input = $this->request->get;
        if ($name) {
            $value = $input[$name] ?? $default;
        } else {
            $value = isset($input) ? $input : [];
        }
        return $value;
    }

    /**
     * getPostParams 获取Post参数
     * @param string|null $name
     * @param mixed $default
     * @return mixed
     */
    public function getPostParams(?string $name = null, mixed $default = null): mixed
    {
        if (!$this->postParams) {
            $input = $this->request->post ?? [];
            if (!$input) {
                $input = json_decode($this->request->rawContent(), true) ?? [];
            }
            $this->postParams = $input;
        }

        if ($name) {
            $value = $this->postParams[$name] ?? $default;
        } else {
            $value = $this->postParams;
        }

        return $value;
    }

    /**
     * getCookieParam
     * @param string|null $name
     * @param mixed $default
     * @return mixed
     */
    public function getCookieParams(?string $name = null, mixed $default = null): mixed
    {
        $cookies = $this->request->cookie;
        if ($name) {
            $value = $cookies[$name] ?? $default;
        } else {
            $value = $cookies ?? [];
        }
        return $value;
    }

    /**
     * getData 获取完整的原始Http请求报文,包括Http Header和Http Body
     * @return string
     */
    public function getData()
    {
        return $this->request->getData();
    }

    /**
     * getServerParam
     * @param string|null $name
     * @param mixed $default
     * @return mixed
     */
    public function getServerParams(?string $name = null, mixed $default = null): mixed
    {
        if ($name) {
            $name = strtoupper($name);
            $value = $this->request->server[$name] ?? $default;
            return $value;
        }
        return $this->request->server;
    }

    /**
     * getHeaderParam
     * @param string|null $name
     * @param mixed $default
     * @return mixed
     */
    public function getHeaderParams(?string $name = null, $default = null): mixed
    {
        if ($name) {
            $name = strtolower($name);
            $value = $this->request->header[$name] ?? $default;
            return $value;
        }

        return $this->request->header;
    }

    /**
     * getFilesParam
     * @return mixed
     */
    public function getUploadFiles(): mixed
    {
        return $this->request->files;
    }

    /**
     * getRawContent
     * @return string|false
     */
    public function getRawContent(): string|false
    {
        return $this->request->rawContent();
    }

    /**
     * getMethod
     * @return string
     */
    public function getMethod(): string
    {
        return $this->request->server['REQUEST_METHOD'];
    }

    /**
     * getRequestUri
     * @return string
     */
    public function getRequestUri(): string
    {
        return $this->request->server['PATH_INFO'];
    }

    /**
     * getRoute
     * @return string
     */
    public function getRoute(): string
    {
        return $this->request->server['ROUTE'];
    }

    /**
     * getQueryString
     * @return string|null
     */
    public function getQueryString(): ?string
    {
        if (isset($this->request->server['QUERY_STRING'])) {
            return $this->request->server['QUERY_STRING'];
        }
        return null;
    }

    /**
     * getProtocol
     * @return string
     */
    public function getProtocol(): string
    {
        return $this->request->server['SERVER_PROTOCOL'];
    }

    /**
     * getHomeUrl 获取当前请求的url
     * @param bool $ssl
     * @return string
     */
    public function getHomeUrl(bool $ssl = false): string
    {
        $protocolVersion = $this->getProtocol();
        list($protocol, $version) = explode('/', $protocolVersion);
        $protocol = strtolower($protocol) . '://';
        if ($ssl) {
            $protocol = 'https://';
        }
        $queryString = $this->getQueryString();
        if ($queryString) {
            $url = $protocol . $this->getHostName() . $this->getRequestUri() . '?' . $queryString;
        } else {
            $url = $protocol . $this->getHostName() . $this->getRequestUri();
        }
        return $url;
    }

    /**
     * rememberUrl
     * @param string|null $name
     * @param string|null $url
     * @param bool $ssl
     * @return void
     */
    public function rememberUrl(?string $name = null, ?string $url = null, bool $ssl = false)
    {
        if ($url && $name) {
            $this->previousUrl[$name] = $url;
        } else {
            // 获取当前的url保存
            $this->previousUrl['home_url'] = $this->getHomeUrl($ssl);
        }
    }

    /**
     * getPreviousUrl
     * @param string|null $name
     * @return mixed
     */
    public function getPreviousUrl(?string $name = null)
    {
        if ($name) {
            if (isset($this->previousUrl[$name])) {
                $previousUrl = $this->previousUrl[$name];
            }
        } else {
            if (isset($this->previousUrl['home_url'])) {
                $previousUrl = $this->previousUrl['home_url'];
            }
        }
        return $previousUrl ?? null;
    }

    /**
     * getRoute
     * @return array
     */
    public function getRouteParams(): array
    {
        return $this->request->server['ROUTE_PARAMS'];
    }

    /**
     * getModule
     * @return string|null
     */
    public function getModuleId(): ?string
    {
        list($count, $routeParams) = $this->getRouteParams();
        if ($count == 3) {
            return $routeParams[0];
        }
        return null;
    }

    /**
     * getController
     * @return string
     */
    public function getControllerId(): string
    {
        list($count, $routeParams) = $this->getRouteParams();
        if ($count == 3) {
            return $routeParams[1];
        } else {
            return $routeParams[0];
        }
    }

    /**
     * getAction
     * @return string
     */
    public function getActionId(): string
    {
        list($count, $routeParams) = $this->getRouteParams();
        return array_pop($routeParams);
    }

    /**
     * getQuery
     * @return array
     */
    public function getQuery(): array
    {
        return $this->request->get;
    }

    /**
     * sendfile
     * @param string $filename
     * @param int $offset
     * @param int $length
     * @return void
     */
    public function sendfile(string $filename, int $offset = 0, int $length = 0)
    {
        $this->response->sendfile($filename, $offset, $length);
    }

    /**
     * parseUrl
     * @param string $url
     * @return array
     */
    public function parseUrl(string $url)
    {
        $parseUrlItems = parse_url($url);
        $parseItems['protocol'] = $parseUrlItems['scheme'];
        $parseItems['host'] = $parseUrlItems['host'];
        $parseItems['port'] = $parseUrlItems['port'];
        $parseItems['user'] = $parseUrlItems['user'];
        $parseItems['pass'] = $parseUrlItems['pass'];
        $parseItems['path'] = $parseUrlItems['path'];
        $parseItems['id'] = $parseUrlItems['fragment'];
        parse_str($parseUrlItems['query'], $parseItems['params']);
        return $parseItems;
    }



    /**
     * getRefererUrl 获取当前页面的上一级页面的来源url
     * @return mixed
     */
    public function getRefererUrl()
    {
        return $this->request->server['HTTP_REFERER'] ?? '';
    }

    /**
     * getClientIP 获取客户端ip
     * @param int $type 返回类型 0:返回IP地址,1:返回IPV4地址数字
     * @return mixed
     */
    public function getClientIP(int $type = 0)
    {
        // 通过nginx的代理
        if (isset($this->request->server['HTTP_X_REAL_IP']) && strcasecmp($this->request->server['HTTP_X_REAL_IP'], "unknown")) {
            $ip = $this->request->server['HTTP_X_REAL_IP'];
        }
        if (isset($this->request->server['HTTP_CLIENT_IP']) && strcasecmp($this->request->server['HTTP_CLIENT_IP'], "unknown")) {
            $ip = $this->request->server["HTTP_CLIENT_IP"];
        }
        if (isset($this->request->server['HTTP_X_FORWARDED_FOR']) and strcasecmp($this->request->server['HTTP_X_FORWARDED_FOR'], "unknown")) {
            return $this->request->server['HTTP_X_FORWARDED_FOR'];
        }
        if (isset($this->request->server['REMOTE_ADDR'])) {
            //没通过代理，或者通过代理而没设置x-real-ip的
            $ip = $this->request->server['REMOTE_ADDR'];
        }
        // IP地址合法验证
        $long = sprintf("%u", ip2long($ip));
        $ip = $long ? [$ip, $long] : ['0.0.0.0', 0];
        return $ip[$type];
    }

    /**
     * getFd 获取当前请求的fd
     * @return int
     */
    public function getFd()
    {
        return $this->request->fd;
    }

    /**
     * getHostName
     * @return string
     */
    public function getHostName(): string
    {
        return $this->request->server['HTTP_HOST'];
    }

    /**
     * @param string $key
     * @param $value
     * @return void
     */
    public function setValue(string $key, $value)
    {
        $this->extendData[$key] = $value;
    }

    /**
     * @param string $key
     * @return mixed
     */
    public function getValue(string $key)
    {
        return $this->extendData[$key];
    }

    /**
     * @return array
     */
    public function getExtendData()
    {
        return $this->extendData;
    }

    /**
     * @return void
     */
    public function setExtendData(array $extendData)
    {
        $this->extendData = $extendData;
    }

    /**
     * getBrowser 获取浏览器
     * @return string
     */
    public function getBrowser(): string
    {
        $sys = $this->request->server['HTTP_USER_AGENT'];
        if (stripos($sys, "Firefox/") > 0) {
            preg_match("/Firefox\/([^;)]+)+/i", $sys, $b);
            $exp[0] = "Firefox";
            $exp[1] = $b[1];
        } elseif (stripos($sys, "Maxthon") > 0) {
            preg_match("/Maxthon\/([\d\.]+)/", $sys, $aoyou);
            $exp[0] = "傲游";
            $exp[1] = $aoyou[1];
        } elseif (stripos($sys, "MSIE") > 0) {
            preg_match("/MSIE\s+([^;)]+)+/i", $sys, $ie);
            $exp[0] = "IE";
            $exp[1] = $ie[1];
        } elseif (stripos($sys, "OPR") > 0) {
            preg_match("/OPR\/([\d\.]+)/", $sys, $opera);
            $exp[0] = "Opera";
            $exp[1] = $opera[1];
        } elseif (stripos($sys, "Edge") > 0) {
            preg_match("/Edge\/([\d\.]+)/", $sys, $Edge);
            $exp[0] = "Edge";
            $exp[1] = $Edge[1];
        } elseif (stripos($sys, "Chrome") > 0) {
            preg_match("/Chrome\/([\d\.]+)/", $sys, $google);
            $exp[0] = "Chrome";
            $exp[1] = $google[1];
        } elseif (stripos($sys, 'rv:') > 0 && stripos($sys, 'Gecko') > 0) {
            preg_match("/rv:([\d\.]+)/", $sys, $IE);
            $exp[0] = "IE";
            $exp[1] = $IE[1];
        } else {
            $exp[0] = "Unkown";
            $exp[1] = "";
        }

        return $exp[0] . '(' . $exp[1] . ')';
    }


}