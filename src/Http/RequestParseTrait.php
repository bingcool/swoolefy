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

use Swoolefy\Library\Validate;
use Swoolefy\Util\IpUtils;

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
    protected ?Validate $validator = null;

    /**
     * @var array
     */
    protected $rules;

    /**
     * @var array
     */
    protected $groupMeta = [];

    /**
     * @var array<string, UploadedFile|list<UploadedFile>>|null
     */
    protected ?array $parsedUploadFiles = null;

    /**
     * @var array
     */
    protected $trustedProxies = [];

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
     * isPatch
     * @return bool
     */
    public function isPatch(): bool
    {
        return (strtoupper($this->swooleRequest->server['REQUEST_METHOD']) == 'PATCH') ? true : false;
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
        if (isset($this->swooleRequest->server['HTTPS']) && ($this->swooleRequest->server['HTTPS'] == '1' || strtolower($this->swooleRequest->server['HTTPS']) == 'on' )) {
            return true;
        } elseif (isset($this->swooleRequest->server['SERVER_PORT']) && ( $this->swooleRequest->server['SERVER_PORT'] == '443')) {
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
        } elseif (isset($this->swooleRequest->server['HTTP_ACCEPT']) && strpos(strtoupper($this->swooleRequest->server['HTTP_ACCEPT']), 'VND.WAP.WML') !== false) {
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
            $get  = isset($this->swooleRequest->get) ? $this->swooleRequest->get : [];
            $post = isset($this->swooleRequest->post) ? $this->swooleRequest->post : [];
            $input = RequestBodyParser::parseJsonPayload(
                (string) $this->getHeaderParams('content-type', ''),
                $this->swooleRequest->rawContent(),
                $this->getMethod()
            );
            $this->requestParams = array_merge($get, $post, $input);
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
     * 当前请求 path（去掉首尾 `/`；根路径返回 `/`）。
     *
     * 读取 App::parseHeaders 规范化后的 PATH_INFO；缺失时从 REQUEST_URI
     * 解析并去掉 query；最终缺失返回 `/`，不触发 undefined key warning。
     *
     * @return string
     */
    public function path()
    {
        $pathInfo = $this->normalizedServerParam('PATH_INFO');
        if ($pathInfo === null || $pathInfo === '') {
            $requestUri = (string) ($this->normalizedServerParam('REQUEST_URI') ?? '');
            if ($requestUri !== '') {
                $parsed = parse_url($requestUri, PHP_URL_PATH);
                $pathInfo = is_string($parsed) ? $parsed : '';
            } else {
                $pathInfo = '';
            }
        }

        $pattern = trim((string) $pathInfo, '/');

        return $pattern === '' ? '/' : $pattern;
    }

    /**
     * 读取 server 参数：优先大写键（parseHeaders 后），兼容 Swoole 原始小写键。
     * Header/server 键名规范化以 App::parseHeaders 为准，调用方勿再混用大小写硬编码。
     *
     * @param string $upperKey 规范化大写键名，如 PATH_INFO、REQUEST_URI
     * @return mixed|null
     */
    protected function normalizedServerParam(string $upperKey)
    {
        $server = $this->swooleRequest->server ?? [];
        if (array_key_exists($upperKey, $server)) {
            return $server[$upperKey];
        }
        $lowerKey = strtolower($upperKey);
        if (array_key_exists($lowerKey, $server)) {
            return $server[$lowerKey];
        }

        return null;
    }

    /**
     * getQueryParams 获取get参数
     * @param string|null $name
     * @param mixed $default
     * @return mixed
     */
    public function getQueryParams(?string $name = null, $default = null)
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
                $input = RequestBodyParser::parseJsonPayload(
                    (string) $this->getHeaderParams('content-type', ''),
                    $this->swooleRequest->rawContent(),
                    $this->getMethod()
                );
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
     * 是否为 multipart/form-data 请求。
     */
    public function isMultipart(): bool
    {
        return str_contains(strtolower((string) $this->getHeaderParams('content-type', '')), 'multipart/form-data');
    }

    /**
     * 指定表单字段是否包含上传文件。
     */
    public function hasFile(string $name): bool
    {
        return array_key_exists($name, $this->files());
    }

    /**
     * 获取上传文件。
     *
     * @return ($name is null ? array<string, UploadedFile|list<UploadedFile>> : UploadedFile|list<UploadedFile>|null)
     */
    public function file(?string $name = null): UploadedFile|array|null
    {
        $files = $this->files();
        if ($name === null) {
            return $files;
        }

        return $files[$name] ?? null;
    }

    /**
     * 获取全部上传文件（已封装为 UploadedFile）。
     *
     * @return array<string, UploadedFile|list<UploadedFile>>
     */
    public function files(): array
    {
        if ($this->parsedUploadFiles === null) {
            $this->parsedUploadFiles = UploadedFile::collectFromSwoole($this->swooleRequest->files ?? []);
        }

        return $this->parsedUploadFiles;
    }

    /**
     * 获取 Swoole 原始 files 数组（同 $_FILES 结构）。
     *
     * @return array<string, mixed>
     */
    public function getUploadFiles(): array
    {
        return $this->swooleRequest->files ?? [];
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
     * getRequestTimeFloat
     * @return float
     */
    public function getRequestTimeFloat()
    {
        return $this->swooleRequest->server['REQUEST_TIME_FLOAT'];
    }

    /**
     * getDispatchRoute
     * @return array
     */
    public function getDispatchRoute(): array
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
     * parseUrl
     * @param string $url
     * @return array
     */
    public function parseUrl(string $url)
    {
        $parseUrlItems = parse_url($url);
        if ($parseUrlItems === false) {
            return [
                'protocol' => null,
                'host'     => null,
                'port'     => null,
                'user'     => null,
                'pass'     => null,
                'path'     => null,
                'id'       => null,
                'params'   => [],
            ];
        }

        $parseItems = [
            'protocol' => $parseUrlItems['scheme'] ?? null,
            'host'     => $parseUrlItems['host'] ?? null,
            'port'     => $parseUrlItems['port'] ?? null,
            'user'     => $parseUrlItems['user'] ?? null,
            'pass'     => $parseUrlItems['pass'] ?? null,
            'path'     => $parseUrlItems['path'] ?? null,
            'id'       => $parseUrlItems['fragment'] ?? null,
            'params'   => [],
        ];

        if (isset($parseUrlItems['query']) && $parseUrlItems['query'] !== '') {
            parse_str($parseUrlItems['query'], $parseItems['params']);
        }

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
        $server = $this->swooleRequest->server ?? [];
        $ip = IpUtils::resolveClientIp($server, $this->trustedProxies);

        if ($type === 1) {
            $long = ip2long($ip);
            return $long === false ? 0 : sprintf("%u", $long);
        }

        return $ip;
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
     * 获取自定义上下文透传的 key-value；键不存在时返回 $default（不再触发 undefined key warning）。
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getValue(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->extendData) ? $this->extendData[$key] : $default;
    }

    /**
     * 强制读取自定义上下文；键不存在时抛异常。
     *
     * @throws \InvalidArgumentException
     */
    public function getRequiredValue(string $key): mixed
    {
        if (!array_key_exists($key, $this->extendData)) {
            throw new \InvalidArgumentException("extendData key [{$key}] is required");
        }

        return $this->extendData[$key];
    }

    /**
     * 判断是否设置自定义上下文透传的key-value数据
     *
     * @param string $key
     * @return bool
     */
    public function hasKeyValue(string $key)
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
        $this->extendData = array_merge($this->extendData, $extendData);
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
     * @param string $name
     * @return bool
     */
    public function hasHeader(string $name): bool
    {
        $headers = $this->getSwooleRequest()->header ?? [];

        return array_key_exists(strtolower($name), $headers);
    }

    /**
     * @param string $name
     * @return bool
     */
    public function hasCookie(string $name)
    {
        $cookies = $this->getSwooleRequest()->cookie;
        return isset($cookies[$name]) ? true : false;
    }

    /**
     * @param string $name
     * @param $default
     * @return mixed|null
     */
    public function cookie(string $name, $default = null)
    {
        return $this->getSwooleRequest()->cookie[$name] ?? $default;
    }

    /**
     * Returns the user.
     *
     * @return string|null
     */
    public function getUser()
    {
        return $this->getSwooleRequest()->header['PHP_AUTH_USER'] ?? null;
    }

    /**
     * Returns the password.
     *
     * @return string|null
     */
    public function getPassword()
    {
        return $this->getSwooleRequest()->header['PHP_AUTH_PW'] ?? null;
    }

    /**
     * @return string|null
     */
    public function getUserInfo()
    {
        $userinfo = $this->getUser();

        $pass = $this->getPassword();
        if ('' != $pass) {
            $userinfo .= ":$pass";
        }

        return $userinfo;
    }

    /**
     * @param array $proxies
     * @return void
     */
    public function setTrustedProxies(array $proxies)
    {
        $this->trustedProxies = $proxies;
    }

    /**
     * 是否来自可信任的IP
     *
     * @return bool
     */
    public function isFromTrustedProxy()
    {
        $remoteIp = $this->swooleRequest->server['REMOTE_ADDR'] ?? '';
        return IpUtils::isTrustedProxyRemoteIp($remoteIp, $this->trustedProxies);
    }

    /**
     * @return array
     */
    public function getTrustedProxies()
    {
        return $this->trustedProxies;
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
        // more call this method
        if ($this instanceof RequestInput) {
            $this->postParams = [];
            $this->requestParams = [];
        }

        return $this->validator;
    }

    /**
     * 粗略识别浏览器名称与版本（仅供调试/展示，勿作安全依据）。
     */
    public function getBrowser(): string
    {
        $sys = (string) (
            $this->swooleRequest->header['user-agent']
            ?? $this->swooleRequest->server['HTTP_USER_AGENT']
            ?? ''
        );
        if ($sys === '') {
            return 'Unkown()';
        }

        $rules = [
            ['Firefox/', '/Firefox\/([^;)\s]+)/i', 'Firefox'],
            ['Maxthon', '/Maxthon\/([\d\.]+)/i', '傲游'],
            ['MSIE', '/MSIE\s+([^;)\s]+)/i', 'IE'],
            ['OPR', '/OPR\/([\d\.]+)/i', 'Opera'],
            ['Edge', '/Edge\/([\d\.]+)/i', 'Edge'],
            ['Chrome', '/Chrome\/([\d\.]+)/i', 'Chrome'],
        ];

        foreach ($rules as [$needle, $pattern, $name]) {
            if (stripos($sys, $needle) === false) {
                continue;
            }
            if (preg_match($pattern, $sys, $matches) === 1) {
                return $name . '(' . ($matches[1] ?? '') . ')';
            }

            return $name . '()';
        }

        if (stripos($sys, 'rv:') !== false && stripos($sys, 'Gecko') !== false) {
            if (preg_match('/rv:([\d\.]+)/i', $sys, $matches) === 1) {
                return 'IE(' . ($matches[1] ?? '') . ')';
            }

            return 'IE()';
        }

        return 'Unkown()';
    }


}
