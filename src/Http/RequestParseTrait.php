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

use Common\Library\Validate;
use mysql_xdevapi\BaseResult;

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
     * @var Validate
     */
    protected $validator;

    /**
     * @var array
     */
    protected $rules;

    protected $groupMeta = [];

    /**
     * isGet
     * @return bool
     */
    public function isGet(): bool
    {
        return (strtoupper($this->swooleRequest->server['REQUEST_METHOD']) == 'GET') ? true : false;
    }

    /**
     * isPost
     * @return bool
     */
    public function isPost(): bool
    {
        return (strtoupper($this->swooleRequest->server['REQUEST_METHOD']) == 'POST') ? true : false;
    }

    /**
     * isPut
     * @return bool
     */
    public function isPut(): bool
    {
        return (strtoupper($this->swooleRequest->server['REQUEST_METHOD']) == 'PUT') ? true : false;
    }

    /**
     * isDelete
     * @return bool
     */
    public function isDelete(): bool
    {
        return (strtoupper($this->swooleRequest->server['REQUEST_METHOD']) == 'DELETE') ? true : false;
    }

    /**
     * isAjax
     * @return bool
     */
    public function isAjax(): bool
    {
        return (isset($this->swooleRequest->header['x-requested-with']) && strtolower($this->swooleRequest->header['x-requested-with']) == 'xmlhttprequest') ? true : false;
    }

    /**
     * isSsl
     * @return bool
     */
    public function isSsl(): bool
    {
        if (isset($this->swooleRequest->server['HTTPS']) && ('1' == $this->swooleRequest->server['HTTPS'] || 'on' == strtolower($this->swooleRequest->server['HTTPS']))) {
            return true;
        } elseif (isset($this->swooleRequest->server['SERVER_PORT']) && ('443' == $this->swooleRequest->server['SERVER_PORT'])) {
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
        if (isset($this->swooleRequest->server['HTTP_VIA']) && stristr($this->swooleRequest->server['HTTP_VIA'], "wap")) {
            return true;
        } elseif (isset($this->swooleRequest->server['HTTP_ACCEPT']) && strpos(strtoupper($this->swooleRequest->server['HTTP_ACCEPT']), "VND.WAP.WML")) {
            return true;
        } elseif (isset($this->swooleRequest->server['HTTP_X_WAP_PROFILE']) || isset($this->swooleRequest->server['HTTP_PROFILE'])) {
            return true;
        } elseif (isset($this->swooleRequest->server['HTTP_USER_AGENT']) && preg_match('/(blackberry|configuration\/cldc|hp |hp-|htc |htc_|htc-|iemobile|kindle|midp|mmp|motorola|mobile|nokia|opera mini|opera |Googlebot-Mobile|YahooSeeker\/M1A1-R2D2|android|iphone|ipod|mobi|palm|palmos|pocket|portalmmm|ppc;|smartphone|sonyericsson|sqh|spv|symbian|treo|up.browser|up.link|vodafone|windows ce|xda |xda_)/i', $this->swooleRequest->server['HTTP_USER_AGENT'])) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * getRequestParam  获取请求参数，包括get,post
     * @param string $name
     * @param mixed $default
     * @return mixed
     */
    public function getRequestParams(?string $name = null, $default = null)
    {
        if (!$this->requestParams) {
            $get = isset($this->swooleRequest->get) ? $this->swooleRequest->get : [];
            $post = isset($this->swooleRequest->post) ? $this->swooleRequest->post : [];
            if (empty($post)) {
                $post = json_decode($this->swooleRequest->rawContent(), true) ?? [];
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
     * @param string|null $name
     * @param $default
     * @return mixed
     */
    public function post(?string $name = null, $default = null)
    {
        return $this->getPostParams($name, $default);
    }

    /**
     * @param string|null $name
     * @param $default
     * @return array|mixed
     */
    public function get(?string $name = null, $default = null)
    {
        return $this->getQueryParams($name, $default);
    }

    /**
     * @param string|null $name
     * @param $default
     * @return mixed
     */
    public function all()
    {
        return $this->getRequestParams(null, null);
    }

    /**
     * getQueryParams 获取get参数
     * @param string|null $name
     * @param mixed $default
     * @return mixed
     */
    protected function getQueryParams(?string $name = null, $default = null)
    {
        $input = $this->swooleRequest->get;
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
    protected function getPostParams(?string $name = null, $default = null)
    {
        if (!$this->postParams) {
            $input = $this->swooleRequest->post ?? [];
            if (!$input) {
                $input = json_decode($this->swooleRequest->rawContent(), true) ?? [];
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
    public function getCookieParams(?string $name = null, $default = null)
    {
        $cookies = $this->swooleRequest->cookie;
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
        return $this->swooleRequest->getData();
    }

    /**
     * getServerParam
     * @param string|null $name
     * @param mixed $default
     * @return mixed
     */
    public function getServerParams(?string $name = null, $default = null)
    {
        if ($name) {
            $name = strtoupper($name);
            $value = $this->swooleRequest->server[$name] ?? $default;
            return $value;
        }
        return $this->swooleRequest->server;
    }

    /**
     * 设置路由分组元信息
     * @param array $groupMeta
     * @return void
     */
    public function setHttpGroupMeta(array $groupMeta)
    {
        $this->groupMeta = $groupMeta;
    }

    /**
     * 路由分组元信息
     * @return array
     */
    public function getHttpGroupMeta(): array
    {
        return $this->groupMeta ?? [];
    }

    /**
     * 路由分组前缀
     *
     * @return mixed
     */
    public function getHttpRoutePrefix()
    {
        return $this->getHttpGroupMeta()['prefix'] ?? '';
    }

    /**
     * getHeaderParam
     * @param string|null $name
     * @param mixed $default
     * @return mixed
     */
    public function getHeaderParams(?string $name = null, $default = null)
    {
        if ($name) {
            $name = strtolower($name);
            $value = $this->swooleRequest->header[$name] ?? $default;
            return $value;
        }

        return $this->swooleRequest->header;
    }

    /**
     * getFilesParam
     * @return mixed
     */
    public function getUploadFiles(): mixed
    {
        return $this->swooleRequest->files;
    }

    /**
     * getRawContent
     * @return string|false
     */
    public function getRawContent()
    {
        return $this->swooleRequest->rawContent();
    }

    /**
     * getMethod
     * @return string
     */
    public function getMethod(): string
    {
        return $this->swooleRequest->server['REQUEST_METHOD'];
    }

    /**
     * getRequestUri
     * @return string
     */
    public function getRequestUri(): string
    {
        return $this->swooleRequest->server['PATH_INFO'];
    }

    /**
     * getDispatchRoute
     * @return array
     */
    public function getDispatchRoute(): string
    {
        return $this->swooleRequest->server['DISPATCH_ROUTE'] ?? [];
    }

    /**
     * getQueryString
     * @return string|null
     */
    public function getQueryString(): ?string
    {
        if (isset($this->swooleRequest->server['QUERY_STRING'])) {
            return $this->swooleRequest->server['QUERY_STRING'];
        }
        return null;
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
     * get current HomeUrl
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
    public function getRouteItems(): array
    {
        return $this->swooleRequest->server['ROUTE_ITEMS'];
    }

    /**
     * getModule
     * @return string|null
     */
    public function getModuleId(): ?string
    {
        list($count, $routeParams) = $this->getRouteItems();
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
        list($count, $routeParams) = $this->getRouteItems();
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
        list($count, $routeParams) = $this->getRouteItems();
        return array_pop($routeParams);
    }

    /**
     * getQuery
     * @return array
     */
    public function getQuery(): array
    {
        return $this->swooleRequest->get;
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
        $this->swooleResponse->sendfile($filename, $offset, $length);
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
     * getRefererUrl
     * @return mixed
     */
    public function getRefererUrl()
    {
        return $this->swooleRequest->server['HTTP_REFERER'] ?? '';
    }

    /**
     * getClientIP
     * @param int $type 返回类型 0:返回IP地址,1:返回IPV4地址数字
     * @return mixed
     */
    public function getClientIP(int $type = 0)
    {
        // 通过nginx的代理
        if (isset($this->swooleRequest->server['HTTP_X_REAL_IP']) && strcasecmp($this->swooleRequest->server['HTTP_X_REAL_IP'], "unknown")) {
            $ip = $this->swooleRequest->server['HTTP_X_REAL_IP'];
        }
        if (isset($this->swooleRequest->server['HTTP_CLIENT_IP']) && strcasecmp($this->swooleRequest->server['HTTP_CLIENT_IP'], "unknown")) {
            $ip = $this->swooleRequest->server["HTTP_CLIENT_IP"];
        }
        if (isset($this->swooleRequest->server['HTTP_X_FORWARDED_FOR']) and strcasecmp($this->swooleRequest->server['HTTP_X_FORWARDED_FOR'], "unknown")) {
            return $this->swooleRequest->server['HTTP_X_FORWARDED_FOR'];
        }
        if (isset($this->swooleRequest->server['REMOTE_ADDR'])) {
            //没通过代理，或者通过代理而没设置x-real-ip的
            $ip = $this->swooleRequest->server['REMOTE_ADDR'];
        }
        // IP地址合法验证
        $long = sprintf("%u", ip2long($ip));
        $ip = $long ? [$ip, $long] : ['0.0.0.0', 0];
        return $ip[$type];
    }

    /**
     * getFd
     * @return int
     */
    public function getFd()
    {
        return $this->swooleRequest->fd;
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
     * 设置自定义上下文透传的key-value数据
     *
     * @param string $key
     * @param $value
     * @return void
     */
    public function setValue(string $key, $value)
    {
        $this->extendData[$key] = $value;
    }

    /**
     * 获取自定义上下文透传的key-value数据
     *
     * @param string $key
     * @return mixed
     */
    public function getValue(string $key)
    {
        return $this->extendData[$key];
    }

    /**
     * 判断是否设置自定义上下文透传的key-value数据
     *
     * @param string $key
     * @return bool
     */
    public function issetValue(string $key)
    {
        return isset($this->extendData[$key]);
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
     * 判断一个字段在请求中是否缺失
     *
     * @param string $name
     * @return bool
     */
    public function missing(string $name): bool
    {
        $value = $this->input($name, null);
        if (is_null($value)) {
            return true;
        }
        return false;
    }

    /**
     * validate request data
     *
     * @param array $params
     * @param array $rules
     * @return Validate|null
     */
    public function validate(array $params, array $rules, array $message = [])
    {
        if (empty($this->validator)) {
            $this->validator = new Validate();
        }

        if (empty($rules)) {
            return $this->validator;
        }

        $this->rules = $rules;
        foreach ($rules as $name => $rule) {
            $this->validator->rule($name, $rule);
        }

        if (!empty($message)) {
            $this->validator->message($message);
        }

        $this->validator->failException(true);
        $this->validator->check($params);

        $fn = function ($method, $value, $fieldRules, $name) {
            if (is_numeric($value)) {
                if (is_string($fieldRules)) {
                    $fieldRules = explode('|', $fieldRules);
                }
                foreach ($fieldRules as $fieldRule) {
                    switch ($fieldRule) {
                        case 'integer':
                        case 'int':
                            $this->swooleRequest->{$method}[$name] = (int) $value;
                            break;
                        case 'float':
                            $this->swooleRequest->{$method}[$name] = (float) $value;
                            break;
                        case 'boolean':
                        case 'bool':
                            $this->swooleRequest->{$method}[$name] = (bool) $value;
                            break;
                        case 'array':
                            if (filter_var($value, FILTER_VALIDATE_INT) !== false) {
                                $this->swooleRequest->{$method}[$name] = [intval($value)];
                            }else {
                                if (filter_var($value, FILTER_VALIDATE_FLOAT) !== false) {
                                    $this->swooleRequest->{$method}[$name] = [floatval($value)];
                                }
                            }
                            break;
                        default:
                            break;
                    }
                }
            }else if(is_array($value)) {
                $pname = $name;
                $name = $name.".*";
                if(isset($this->rules[$name])) {
                    $fieldRules = $this->rules[$name];
                    if (is_string($fieldRules)) {
                        $fieldRules = explode('|', $fieldRules);
                    }
                    foreach ($fieldRules as $fieldRule) {
                        switch ($fieldRule) {
                            case 'integer':
                            case 'int':
                                $newValue = array_map('intval', $value);
                                $this->swooleRequest->{$method}[$pname] = $newValue;
                                break;
                            case 'float':
                                $newValue = array_map('floatval', $value);
                                $this->swooleRequest->{$method}[$pname] = $newValue;
                                break;
                            default:
                                break;
                        }
                    }
                }
            }
        };

        foreach ($rules as $name => $fieldRules) {
            if (isset($this->swooleRequest->get[$name])) {
                $value = $this->swooleRequest->get[$name];
                if ($fieldRules) {
                    $fn('get', $value, $fieldRules,$name);
                }
            }

            if (isset($this->swooleRequest->post[$name])) {
                $value = $this->swooleRequest->post[$name];
                if ($fieldRules) {
                    $fn('post', $value, $fieldRules, $name);
                }
            }
        }
        $this->rules = [];
        // more import
        if ($this instanceof RequestInput) {
            $this->postParams = [];
            $this->requestParams = [];
        }

        return $this->validator;
    }

    /**
     * getBrowserType
     * @return string
     */
    public function getBrowser(): string
    {
        $sys = $this->swooleRequest->server['HTTP_USER_AGENT'];
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