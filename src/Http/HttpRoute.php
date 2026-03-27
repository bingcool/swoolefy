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

use Common\Library\CurlProxy\OpentelemetryMiddleware;
use Swoolefy\Core\App;
use Swoolefy\Core\AppDispatch;
use Swoolefy\Core\Application;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Core\Coroutine\Context as SwooleContext;
use Swoolefy\Core\Dto\AbstractDto;
use Swoolefy\Core\RouteMiddlewareInterface;
use Swoolefy\Core\SystemEnv;
use Swoolefy\Exception\CorsRespException;
use Swoolefy\Exception\DispatchException;
use Swoolefy\Exception\SystemException;
use Swoolefy\Http\Middleware\CorsMiddlewareInterface;

class HttpRoute extends AppDispatch
{

    const ITEM_NUM_3 = 3;

    const ITEM_NUM_5 = 5;

    const DISPATCH_ROUTE = 'dispatch_route';
    const PARAM_KIND_REQUEST_INPUT = 'request_input';
    const PARAM_KIND_RESPONSE_OUTPUT = 'response_output';
    const PARAM_KIND_DTO = 'dto';
    const PARAM_KIND_DEFAULT = 'default';

    /**
     * $appConf
     * @var array
     */
    protected $appConf = [];

    /**
     * $app
     * @var App
     */
    protected $app = null;

    /**
     * @var RequestInput
     */
    protected $requestInput;

    /**
     * @var ResponseOutput
     */
    protected $responseOutput;

    /**
     * $routerUri
     * @var array
     */
    protected $dispatchRoute = [];

    /**
     * $extendData
     * @var mixed
     */
    protected $extendData = null;

    /**
     * @var array
     */
    protected $actionParams = [];

    /**
     * @var array
     */
    protected $groupMiddlewares = [];

    /**
     * @var array
     */
    protected $beforeMiddlewares = [];

    /**
     * @var array|mixed
     */
    protected $afterMiddlewares = [];

    /**
     * @var string
     */
    protected $httpMethod;

    /**
     * @var array
     */
    protected $groupMeta;

    /**
     * @var RouteOption
     */
    protected $routeOption;

    /**
     * @var array
     */
    protected static $routeCache;

    /**
     * 控制器 action 参数元数据缓存
     *
     * @var array
     */
    protected static $actionParamMetaCache = [];

    /**
     * 控制器命名空间解析缓存
     *
     * @var array
     */
    protected static $dispatchControllerMetaCache = [];

    /**
     * $denyActions
     * @var array
     */
    protected static $denyActions = ['__construct', '_beforeAction', '_afterAction', '__destruct'];

    /**
     * @param RequestInput $requestInput
     * @param ResponseOutput $responseOutput
     * @param $extendData
     */
    public function __construct(RequestInput $requestInput, ResponseOutput $responseOutput, $extendData = null)
    {
        parent::__construct();
        $this->app        = Application::getApp();
        $this->appConf    = $this->app->appConf;
        $this->requestInput = $requestInput;
        $this->responseOutput = $responseOutput;
        $this->extendData = $extendData;
        $this->httpMethod = $this->requestInput->getMethod();
        list(
            $this->groupMiddlewares,
            $this->beforeMiddlewares,
            $this->dispatchRoute,
            $this->afterMiddlewares,
            $this->groupMeta,
            $this->routeOption,
        )  = $this->getHttpRouterMapUri($this->requestInput->getRequestUri(), $this->httpMethod);
        $this->requestInput->setHttpGroupMeta($this->groupMeta);
    }

    /**
     * dispatch
     * @return mixed
     * @throws \Throwable
     */
    public function dispatch()
    {
        if (!isset($this->appConf['app_namespace']) || $this->appConf['app_namespace'] != APP_NAME ) {
            $this->appConf['app_namespace'] = APP_NAME;
        }

        $controllerNamespace = $this->dispatchRoute[0];
        $action = $this->dispatchRoute[1];

        [$module, $controller] = self::parseDispatchControllerMeta(
            $controllerNamespace,
            $this->isEnableRouteMetaCache($this->routeOption)
        );

        // forbidden call action
        if (in_array($action, static::$denyActions, true)) {
            $errorMsg = "{$controller}::{$action} is forbidden access this action";
            throw new DispatchException($errorMsg, \Swoole\Http\Status::FORBIDDEN);
        }

        // validate class
        $controllerValidateName = str_replace('Controller','Validation', $controllerNamespace);
        if (method_exists($controllerValidateName, $action) && $controllerValidateName != $controllerNamespace) {
            $validation = new $controllerValidateName();
            $validateRule = $validation->{$action}();
            $this->requestInput->validate($this->requestInput->all(), $validateRule['rules'] ?? [], $validateRule['messages'] ?? []);
        }

        if (isset($module)) {
            // route params array
            $routeItems = [3, [$module, $controller, $action]];
        } else {
            // route params array
            $routeItems = [2, [$controller, $action]];
        }
        // route params array attach to server
        $this->requestInput->getSwooleRequest()->server['ROUTE_ITEMS']    = $routeItems;
        $this->requestInput->getSwooleRequest()->server['DISPATCH_ROUTE'] = $this->dispatchRoute;
        $this->invoke($this->dispatchRoute[0], $action);
        return true;
    }

    /**
     * @param string $class
     * @param string $action
     * @return bool
     */
    protected function invoke(
        string $class,
        string $action
    ): bool
    {
        if (!class_exists($class)) {
            list($class, $action) = $this->fileNotFound($class);
        }

        if ($this->app->isEnd()) {
            throw new SystemException('System Request End Error', \Swoole\Http\Status::INTERNAL_SERVER_ERROR);
        }

        // reset app conf
        $this->app->setAppConf($this->appConf);

        // api limit rate
        if (is_object($this->routeOption)) {
            $this->requestInput->setValue(RouteOption::API_LIMIT_NUM_KEY, $this->routeOption->getLimitNum());
            $this->requestInput->setValue(RouteOption::API_LIMIT_WINDOW_SIZE_TIME_KEY, $this->routeOption->getWindowSizeTime());
            // 是否动态开启db-debug
            SwooleContext::set('db_debug', $this->routeOption->isEnableDbDebug());
        }

        try {
            // handle route group middles
            $this->handleGroupRouteMiddles($this->httpMethod);
            // handle before route middles
            $this->handleBeforeRouteMiddles($this->httpMethod);
        } catch (CorsRespException $e) {
            // cors response and over finish and stop next call
            return false;
        }

        /**@var BController $controllerInstance */
        $controllerInstance = new $class();
        // set Controller Instance
        $this->app->setControllerInstance($controllerInstance);

        // invoke _beforeAction
        $isContinueAction = $controllerInstance->_beforeAction($this->requestInput, $action);

        if ($isContinueAction === false) {
            $errorMsg = sprintf(
                "Call %s::_beforeAction() return false, forbidden call %s::%s",
                $class,
                $class,
                $action
            );

            throw new DispatchException($errorMsg, \Swoole\Http\Status::FORBIDDEN);
        }
        // reflector params handle
        $args = $this->bindActionParams($controllerInstance, $action, $this->requestInput->all());
        $controllerInstance->{$action}(...$args);
        if (!SystemEnv::isPrdEnv()) {
            $traceId = '';
            if (SwooleContext::has(OpentelemetryMiddleware::OPENTELEMETRY_X_TRACE_ID)) {
                $traceId = SwooleContext::get(OpentelemetryMiddleware::OPENTELEMETRY_X_TRACE_ID);
            }
            fmtPrintInfo(sprintf("[request end] %s: [%s %s] 请求耗时: %s秒,[request-id] %s",
                date('Y-m-d H:i:s'),
                $this->requestInput->getSwooleRequest()->server['REQUEST_METHOD'],
                $this->requestInput->getRequestUri(),
                round($this->requestEndTime() - $this->requestInput->getRequestTimeFloat(), 3),
                $traceId,
            ));
        }
        $controllerInstance->_afterAction($this->requestInput, $action);
        $this->handleAfterRouteMiddles();
        return true;
    }

    /**
     * @return float
     */
    protected function requestEndTime()
    {
        $microtime = microtime(true);
        $seconds = floor($microtime);
        $milliseconds = round(($microtime - $seconds) * 1000);
        return floatval($seconds . '.' . sprintf('%03d', $milliseconds));
    }

    /**
     * group middles
     *
     * @return void
     */
    private function handleGroupRouteMiddles(string $method)
    {
        $this->runMiddlewares($this->groupMiddlewares, $method);
    }

    /**
     * before route middles
     * @return void
     */
    private function handleBeforeRouteMiddles(string $method)
    {
        $this->runMiddlewares($this->beforeMiddlewares, $method, true, true);
    }

    /**
     * handleAfterRouteMiddles
     *
     * @return void
     */
    private function handleAfterRouteMiddles()
    {
        $this->runMiddlewares($this->afterMiddlewares, '', true, false, false);
    }

    /**
     * 统一处理中间件，避免重复流程分支
     *
     * @param array $middlewares
     * @param string $method
     * @param bool $supportClosure
     * @param bool $throwWhenClosureFalse
     * @param bool $supportCors
     * @return void
     */
    private function runMiddlewares(
        array $middlewares,
        string $method = '',
        bool $supportClosure = false,
        bool $throwWhenClosureFalse = false,
        bool $supportCors = true
    ): void {
        foreach ($middlewares as $middleware) {
            if ($supportClosure && $middleware instanceof \Closure) {
                $result = $middleware($this->requestInput, $this->responseOutput);
                if ($throwWhenClosureFalse && $result === false) {
                    throw new SystemException('beforeHandle route middle return false, Not Allow Coroutine To Next Middle', \Swoole\Http\Status::INTERNAL_SERVER_ERROR);
                }
                continue;
            }

            if (!is_string($middleware) || !class_exists($middleware)) {
                continue;
            }

            $middlewareEntity = new $middleware();
            // cors middleware 一次调用即可，避免重复执行 handle()
            if ($supportCors && $middlewareEntity instanceof CorsMiddlewareInterface) {
                $preflightResult = $middlewareEntity->handle($this->requestInput, $this->responseOutput);
                if ($method === 'OPTIONS' || empty($preflightResult)) {
                    throw new CorsRespException();
                }
                continue;
            }

            if ($middlewareEntity instanceof RouteMiddlewareInterface) {
                $middlewareEntity->handle($this->requestInput, $this->responseOutput);
            }
        }
    }

    /**
     * @param string $class
     * @return array
     */
    private function fileNotFound(string $class): array
    {
        if (isset($this->appConf['not_found_handler']) && is_array($this->appConf['not_found_handler'])) {
            // reset NotFound class
            list($class, $action) = $this->appConf['not_found_handler'];
            return [$class, $action];
        } else {
            $errorMsg = "Class `{$class}` Not Found";
            throw new DispatchException($errorMsg, \Swoole\Http\Status::NOT_FOUND);
        }
    }

    /**
     * resetRouteDispatch 重置路由调度,将实际的路由改变请求,主要用在bootstrap()中
     * @param string $route 请求的路由uri
     * @return void
     */
    public static function resetRouteDispatch(string $route)
    {
        $route = trim($route, DIRECTORY_SEPARATOR);
        Application::getApp()->swooleRequest->server['PATH_INFO'] = DIRECTORY_SEPARATOR . $route;
    }

    /**
     * @param BController $controllerInstance
     * @param string $action
     * @param array $params
     * @return array
     * @throws DispatchException
     */
    protected function bindActionParams(BController $controllerInstance, string $action, array $params): array
    {
        $class = get_class($controllerInstance);
        if ($this->isEnableRouteMetaCache($this->routeOption)) {
            $cacheKey = $class . '::' . $action;
            if (!isset(self::$actionParamMetaCache[$cacheKey])) {
                self::$actionParamMetaCache[$cacheKey] = $this->buildActionParamMeta($class, $action);
            }
            $paramMetas = self::$actionParamMetaCache[$cacheKey];
        } else {
            $paramMetas = $this->buildActionParamMeta($class, $action);
        }

        $args = $missing = $actionParams = [];
        $inputParams = null;

        foreach ($paramMetas as $paramMeta) {
            $kind = $paramMeta['kind'];
            if ($kind === self::PARAM_KIND_REQUEST_INPUT) {
                $args[] = $this->requestInput;
                continue;
            }

            if ($kind === self::PARAM_KIND_RESPONSE_OUTPUT) {
                $args[] = $this->responseOutput;
                continue;
            }

            if ($kind === self::PARAM_KIND_DTO) {
                $dtoClass = $paramMeta['dto_class'];
                $paramDto = new $dtoClass();
                $inputParams = $inputParams ?? $this->requestInput->input();
                $propertyList = get_object_vars($paramDto);
                foreach ($propertyList as $property => $value) {
                    $paramDto->{$property} = $inputParams[$property] ?? $value;
                }
                $args[] = $paramDto;
                continue;
            }

            $name = $paramMeta['name'];
            if (array_key_exists($name, $params)) {
                $isValid = true;
                $value = $params[$name];
                if ($paramMeta['is_array']) {
                    $value = (array)$value;
                } else if (is_array($value)) {
                    $isValid = false;
                } else if (
                    !empty($paramMeta['builtin_type']) &&
                    ($value !== null || !$paramMeta['allows_null'])
                ) {
                    switch ($paramMeta['builtin_type']) {
                        case 'int':
                        case 'integer':
                            $value = filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);
                            break;
                        case 'float':
                            $value = filter_var($value, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE);
                            break;
                    }
                    if ($value === null) {
                        $isValid = false;
                    }
                }

                if (!$isValid) {
                    throw new DispatchException("Invalid data received for parameter of {$name}" . '|||' . $this->requestInput->getSwooleRequest()->server['REQUEST_URI']);
                }

                $args[] = $actionParams[$name] = $value;
                unset($params[$name]);
                continue;
            }

            if ($paramMeta['has_default']) {
                $args[] = $actionParams[$name] = $paramMeta['default'];
                continue;
            }

            if ($kind === self::PARAM_KIND_DEFAULT) {
                $missing[] = $name;
            }
        }

        if (!empty($missing)) {
            throw new DispatchException("Missing function required params [" . implode(', ', $missing) . '] |||' . $this->requestInput->getSwooleRequest()->server['REQUEST_URI'] . '|||' . json_encode($actionParams, JSON_UNESCAPED_UNICODE));
        }

        $this->actionParams = $actionParams;

        return $args;
    }

    /**
     * 构建并缓存 action 参数元数据，减少每次请求反射成本
     *
     * @param string $class
     * @param string $action
     * @return array
     */
    private function buildActionParamMeta(string $class, string $action): array
    {
        $method = new \ReflectionMethod($class, $action);
        $paramMetas = [];
        foreach ($method->getParameters() as $param) {
            $type = $param->getType();
            $typeName = null;
            $allowsNull = false;
            $builtinType = null;

            if ($type instanceof \ReflectionNamedType) {
                $typeName = $type->getName();
                $allowsNull = $type->allowsNull();
                if ($type->isBuiltin()) {
                    $builtinType = $typeName;
                }
            }

            if ($typeName === RequestInput::class) {
                $paramMetas[] = ['kind' => self::PARAM_KIND_REQUEST_INPUT];
                continue;
            }

            if ($typeName === ResponseOutput::class) {
                $paramMetas[] = ['kind' => self::PARAM_KIND_RESPONSE_OUTPUT];
                continue;
            }

            if ($typeName && class_exists($typeName) && is_subclass_of($typeName, AbstractDto::class)) {
                $paramMetas[] = [
                    'kind' => self::PARAM_KIND_DTO,
                    'dto_class' => $typeName,
                ];
                continue;
            }

            $paramMetas[] = [
                'kind' => self::PARAM_KIND_DEFAULT,
                'name' => $param->getName(),
                'is_array' => $typeName === 'array',
                'builtin_type' => $builtinType,
                'allows_null' => $allowsNull,
                'has_default' => $param->isDefaultValueAvailable(),
                'default' => $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null,
            ];
        }

        return $paramMetas;
    }

    /**
     * @param string $uri
     * @return array
     */
    protected function getHttpRouterMapUri(string $uri, string $method): array
    {
        $uri = DIRECTORY_SEPARATOR.trim($uri,DIRECTORY_SEPARATOR);
        $method = strtoupper($method);
        if (isset(self::$routeCache[$uri][$method])) {
            return self::$routeCache[$uri][$method];
        }

        $routerMap = Route::loadRouteFile();
        if (isset($routerMap[$uri][$method]['route_meta'])) {
            $routerMapInfo = $routerMap[$uri][$method];
            $groupMeta  = $routerMapInfo['group_meta'] ?? [];
            $routerMeta = $routerMapInfo['route_meta'];
            $groupMiddlewares = $routerMapInfo['group_meta']['middleware'] ?? [];
            /**
             * @var RouteOption $routeOption
             */
            $routeOption = $routerMapInfo['route_option'] ?? null;
            $enableCacheRouteMeta = $this->isEnableRouteMetaCache($routeOption);
            if (!isset($routerMeta[self::DISPATCH_ROUTE])) {
                throw new DispatchException("Missing `dispatch_route` item ");
            } else {
                $originDispatchRoute = $routerMeta[self::DISPATCH_ROUTE];
                self::parseDispatchControllerMeta($originDispatchRoute[0] ?? null, $enableCacheRouteMeta);
            }

            $beforeMiddlewares = $afterMiddlewares = [];
            $isAfterDispatchRoute = false;
            foreach ($routerMeta as $alias => $handle) {
                // 调度路由之后的是后置中间件
                if ($alias === self::DISPATCH_ROUTE) {
                    $isAfterDispatchRoute = true;
                    continue;
                }

                if ($isAfterDispatchRoute) {
                    $this->appendRouteMiddleware($afterMiddlewares, $handle);
                } else {
                    $this->appendRouteMiddleware($beforeMiddlewares, $handle);
                }
            }

            $rateLimiterMiddleware = $runAfterMiddleware = '';
            if (is_object($routeOption)) {
                $rateLimiterMiddleware = $routeOption->getRateLimiterMiddleware();
                $runAfterMiddleware    = $routeOption->getRunAfterMiddleware();
            }

            if ($rateLimiterMiddleware && empty($runAfterMiddleware)) {
                // 放在Group Middleware最前面执行
                array_unshift($groupMiddlewares, $rateLimiterMiddleware);
            } else if ($rateLimiterMiddleware && class_exists($rateLimiterMiddleware)) {
                $tempGroupMiddlewares = [];
                $isMatch = self::parseLateMiddleware($groupMiddlewares, $rateLimiterMiddleware, $runAfterMiddleware,$tempGroupMiddlewares);
                $groupMiddlewares = $tempGroupMiddlewares;
                unset($tempGroupMiddlewares);

                // Group Middlewares 没有匹配到，继续BeforeMiddlewares来匹配
                if (!$isMatch) {
                    $tempBeforeMiddlewares = [];
                    self::parseLateMiddleware($beforeMiddlewares, $rateLimiterMiddleware, $runAfterMiddleware,$tempBeforeMiddlewares);
                    $beforeMiddlewares = $tempBeforeMiddlewares;
                    unset($tempBeforeMiddlewares);
                }
            }

            $routeCacheItems = [$groupMiddlewares, $beforeMiddlewares, $originDispatchRoute, $afterMiddlewares, $groupMeta, $routeOption];
            self::$routeCache[$uri][$method] = $routeCacheItems;
            unset($routerMap[$uri][$method]);
            return $routeCacheItems;
        } else {
            if (!isset($routerMap[$uri])) {
                throw new DispatchException("Not Found Route [$uri].");
            } else if (isset($routerMap[$uri]) && !isset($routerMap[$uri][$method])) {
                $methods = array_keys($routerMap[$uri]);
                $methods = implode(',', $methods);
                throw new DispatchException("Only Support Http Method=[{$methods}], But You Current Request Method={$method}, route=[$uri], Please check route config.");
            } else {
                throw new DispatchException("Not Match Route [$uri].");
            }
        }
    }

    /**
     * @param $middlewares
     * @param $rateLimiterMiddleware
     * @param $runAfterMiddleware
     * @param $tempMiddlewares
     * @return bool
     */
    protected static function parseLateMiddleware($middlewares, $rateLimiterMiddleware, $runAfterMiddleware, &$tempMiddlewares): bool
    {
        $isMatch = false;
        foreach ($middlewares as $middleware) {
            $tempMiddlewares[] = $middleware;
            if (is_string($middleware) && $runAfterMiddleware == $middleware) {
                if (!in_array($rateLimiterMiddleware, $tempMiddlewares)) {
                    // 在runAfterMiddleware后插入rateLimiterMiddleware
                    $tempMiddlewares[] = $rateLimiterMiddleware;
                    $isMatch = true;
                }
            }
        }
        return $isMatch;
    }

    /**
     * 按序展开 middleware 配置
     *
     * @param array $middlewares
     * @param mixed $handle
     * @return void
     */
    private function appendRouteMiddleware(array &$middlewares, $handle): void
    {
        if (is_array($handle)) {
            foreach ($handle as $handleItem) {
                $middlewares[] = $handleItem;
            }
            return;
        }

        $middlewares[] = $handle;
    }

    /**
     * 解析 dispatch route controller 部分，包含格式校验和按路由开关缓存
     *
     * @param mixed $controllerNamespace
     * @param bool $enableCacheRouteMeta
     * @return array [module|null, controller]
     * @throws DispatchException
     */
    private static function parseDispatchControllerMeta($controllerNamespace, bool $enableCacheRouteMeta = false): array
    {
        if (!is_string($controllerNamespace) || $controllerNamespace === '') {
            throw new DispatchException("Dispatch Route Class Error");
        }

        if ($enableCacheRouteMeta && isset(self::$dispatchControllerMetaCache[$controllerNamespace])) {
            return self::$dispatchControllerMetaCache[$controllerNamespace];
        }

        $dispatchRouteItem = explode("\\", $controllerNamespace);
        $count = count($dispatchRouteItem);
        if ($count === static::ITEM_NUM_3) {
            $dispatchControllerMeta = [null, $dispatchRouteItem[2]];
        } else if ($count === static::ITEM_NUM_5) {
            $dispatchControllerMeta = [$dispatchRouteItem[2], $dispatchRouteItem[4]];
        } else {
            throw new DispatchException("Dispatch Route Class Error");
        }

        if ($enableCacheRouteMeta) {
            self::$dispatchControllerMetaCache[$controllerNamespace] = $dispatchControllerMeta;
        }

        return $dispatchControllerMeta;
    }

    /**
     * 当前请求路由是否开启了路由元信息缓存
     *
     * @return bool
     */
    private function isEnableRouteMetaCache(RouteOption $routeOption): bool
    {
        return $routeOption->isEnableCacheRouteMeta();
    }

    /**
     * @param string $uri
     * @return bool
     */
    public function hasRoute(string $uri)
    {
        $uri = DIRECTORY_SEPARATOR.trim($uri,DIRECTORY_SEPARATOR);
        $routerMap = Route::loadRouteFile();
        return isset(self::$routeCache[$uri]) || isset($routerMap[$uri]);
    }

    /**
     * __destruct
     */
    public function __destruct()
    {
        unset($this->app, $this->requestInput, $this->responseOutput);
    }
}
