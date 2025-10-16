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

use Swoolefy\Core\App;
use Swoolefy\Core\AppDispatch;
use Swoolefy\Core\Application;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Core\Coroutine\Context;
use Swoolefy\Core\Dto\AbstractDto;
use Swoolefy\Core\RouteMiddleware;
use Swoolefy\Core\SystemEnv;
use Swoolefy\Exception\DispatchException;
use Swoolefy\Exception\SystemException;

class HttpRoute extends AppDispatch
{

    const ITEM_NUM_3 = 3;

    const ITEM_NUM_5 = 5;

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

        $dispatchRouteItem = explode("\\", $controllerNamespace);
        $count = count($dispatchRouteItem);
        $controller = '';
        switch ($count) {
            case static::ITEM_NUM_3:
                $module = null;
                $controller = $dispatchRouteItem[2];
                break;
            case static::ITEM_NUM_5 :
                $module = $dispatchRouteItem[2];
                $controller = $dispatchRouteItem[4];
                break;
        }

        // forbidden call action
        if (in_array($action, static::$denyActions)) {
            $errorMsg = "{$controller}::{$action} is not allow access action";
            throw new DispatchException($errorMsg, 403);
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
            throw new SystemException('System Request End Error', 500);
        }

        // reset app conf
        $this->app->setAppConf($this->appConf);
        // api limit rate
        $this->requestInput->setValue(RouteOption::API_LIMIT_NUM_KEY, $this->routeOption->getLimitNum());
        $this->requestInput->setValue(RouteOption::API_LIMIT_WINDOW_SIZE_TIME_KEY, $this->routeOption->getWindowSizeTime());

        // 是否动态开启db-debug
        if ($this->routeOption->isEnableDbDebug()) {
            Context::set('db_debug', true);
        }

        // handle route group middles
        $this->handleGroupRouteMiddles();

        // handle before route middles
        $this->handleBeforeRouteMiddles();

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

            throw new DispatchException($errorMsg, 403);
        }
        // reflector params handle
        list($method, $args) = $this->bindActionParams($controllerInstance, $action, $this->requestInput->all());
        $controllerInstance->{$action}(...$args);
        if (!SystemEnv::isPrdEnv()) {
            fmtPrintInfo(sprintf("[request end] %s: [%s %s] 请求耗时: %ss",
                date('Y-m-d H:i:s'),
                $this->requestInput->getSwooleRequest()->server['REQUEST_METHOD'],
                $this->requestInput->getRequestUri(),
                round($this->requestEndTime() - $this->requestInput->getRequestTimeFloat(), 3),
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
    private function handleGroupRouteMiddles()
    {
        foreach ($this->groupMiddlewares as $middleware) {
            if (class_exists($middleware)) {
                $middlewareHandleEntity = new $middleware;
                if ($middlewareHandleEntity instanceof RouteMiddleware) {
                    $middlewareHandleEntity->handle($this->requestInput, $this->responseOutput);
                }
            }
        }
    }

    /**
     * before route middles
     * @return void
     */
    private function handleBeforeRouteMiddles()
    {
        foreach($this->beforeMiddlewares as $middleware) {
            if ($middleware instanceof \Closure) {
                $result = call_user_func($middleware, $this->requestInput, $this->responseOutput);
                if ($result === false) {
                    throw new SystemException('beforeHandle route middle return false, Not Allow Coroutine To Next Middle', 500);
                }
            }else if (class_exists($middleware)) {
                $middlewareEntity = new $middleware;
                if ($middlewareEntity instanceof RouteMiddleware) {
                    $middlewareEntity->handle($this->requestInput, $this->responseOutput);
                }
            }
        }
    }

    /**
     * handleAfterRouteMiddles
     *
     * @return void
     */
    private function handleAfterRouteMiddles()
    {
        foreach ($this->afterMiddlewares as $middleware) {
            if ($middleware instanceof \Closure) {
                call_user_func($middleware, $this->requestInput, $this->responseOutput);
            }else if (class_exists($middleware)) {
                $middlewareEntity = new $middleware;
                if ($middlewareEntity instanceof RouteMiddleware) {
                    $middlewareEntity->handle($this->requestInput, $this->responseOutput);
                }
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
            $errorMsg = "Class {$class} is not found";
            throw new DispatchException($errorMsg, 404);
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
     * @param $action
     * @param $params
     * @return array
     * @throws DispatchException
     */
    protected function bindActionParams(BController $controllerInstance, $action, $params)
    {
        $method = new \ReflectionMethod($controllerInstance, $action);
        $args = $missing = $actionParams = [];
        foreach ($method->getParameters() as $param) {
            $name = $param->getName();
            $hasType = $param->hasType();
            $typeName = $param->getType()->getName();
            if ($hasType && $typeName == RequestInput::class) {
                $args[] = $this->requestInput;
            }else if ($hasType && $typeName == ResponseOutput::class) {
                $args[] = $this->responseOutput;
            }else if ($hasType && class_exists($typeName) && is_subclass_of($typeName,AbstractDto::class)) {
                $paramDto     = new $typeName();
                $inputParams  = $this->requestInput->input();
                $propertyList = get_object_vars($paramDto);
                foreach ($propertyList as $property => $value) {
                    $paramDto->{$property} = $inputParams[$property] ?? $value;
                }
                $args[] = $paramDto;
            }else if (array_key_exists($name, $params)) {
                $isValid = true;
                if ($param->hasType() && $param->getType()->getName() == 'array') {
                    $params[$name] = (array)$params[$name];
                } else if (is_array($params[$name])) {
                    $isValid = false;
                } else if (
                    ($type = $param->getType()) !== null &&
                    $type->isBuiltin() &&
                    ($params[$name] !== null || !$type->allowsNull())
                ) {
                    $typeName = $type->getName();
                    switch ($typeName) {
                        case 'int':
                        case 'integer':
                            $params[$name] = filter_var($params[$name], FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);
                            break;
                        case 'float':
                            $params[$name] = filter_var($params[$name], FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE);
                            break;
                    }
                    if ($params[$name] === null) {
                        $isValid = false;
                    }
                }

                if (!$isValid) {
                    throw new DispatchException("Invalid data received for parameter of {$name}" . '|||' . $this->requestInput->getSwooleRequest()->server['REQUEST_URI']);
                }

                $args[] = $actionParams[$name] = $params[$name];
                unset($params[$name]);
            } else if ($param->isDefaultValueAvailable()) {
                $args[] = $actionParams[$name] = $param->getDefaultValue();
            } else {
                $missing[] = $name;
            }
        }

        if (!empty($missing)) {
            throw new DispatchException("Missing function required params [" . implode(', ', $missing) . '] |||' . $this->requestInput->getSwooleRequest()->server['REQUEST_URI'] . '|||' . json_encode($actionParams, JSON_UNESCAPED_UNICODE));
        }

        $this->actionParams = $actionParams;

        return [$method, $args];
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
            $routeOption = $routerMapInfo['route_option'];
            if(!isset($routerMeta['dispatch_route'])) {
                throw new DispatchException("Missing dispatch_route");
            }else {
                $originDispatchRoute = $routerMeta['dispatch_route'];
                $dispatchRoute = str_replace("\\",DIRECTORY_SEPARATOR, $routerMeta['dispatch_route'][0]);
                $dispatchRouteItems = explode(DIRECTORY_SEPARATOR, $dispatchRoute);
                $itemNum = count($dispatchRouteItems);
                if(!in_array($itemNum, [static::ITEM_NUM_3, static::ITEM_NUM_5])) {
                    throw new DispatchException("Dispatch Route Class Error");
                }
            }

            $beforeMiddlewares = $afterMiddlewares = [];
            foreach($routerMeta as $alias => $handle) {
                if ($alias != 'dispatch_route') {
                    if (is_array($handle)) {
                        foreach ($handle as $handleItem) {
                            $beforeMiddlewares[] = $handleItem;
                        }
                    }else {
                        $beforeMiddlewares[] = $handle;
                    }
                    unset($routerMeta[$alias]);
                    continue;
                }
                unset($routerMeta[$alias]);
                break;
            }

            $afterMiddlewareTemp = array_values($routerMeta);
            foreach ($afterMiddlewareTemp as $afterMiddlewareItem) {
                if (is_array($afterMiddlewareItem)) {
                    foreach ($afterMiddlewareItem as $afterMiddlewareEntry) {
                        $afterMiddlewares[] = $afterMiddlewareEntry;
                    }
                }else {
                    $afterMiddlewares[] = $afterMiddlewareItem;
                }
            }

            $rateLimiterMiddleware = $routeOption->getRateLimiterMiddleware();
            $runAfterMiddleware    = $routeOption->getRunAfterMiddleware();
            if ($rateLimiterMiddleware && empty($runAfterMiddleware)) {
                // 放在Group Middleware最前面执行
                array_unshift($groupMiddlewares, $rateLimiterMiddleware);
            }else if ($rateLimiterMiddleware && class_exists($rateLimiterMiddleware)) {
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
        }else {
            if (!isset($routerMap[$uri])) {
                throw new DispatchException("Not Found Route [$uri].");
            }else if (isset($routerMap[$uri]) && !isset($routerMap[$uri][$method])) {
                $methods = array_keys($routerMap[$uri]);
                $methods = implode(',', $methods);
                throw new DispatchException("Only Support Http Method=[{$methods}], But You Current Request Method={$method}, route=[$uri], Please check route config.");
            }else {
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
     * @param string $uri
     * @return bool
     */
    public function hasRoute(string $uri)
    {
        $uri = DIRECTORY_SEPARATOR.trim($uri,DIRECTORY_SEPARATOR);
        $routerMap = Route::loadRouteFile();
        if (isset(self::$routeCache[$uri]) || isset($routerMap[$uri])) {
            return true;
        }
        return false;
    }

    /**
     * __destruct
     */
    public function __destruct()
    {
        unset($this->app, $this->requestInput, $this->responseOutput);
    }
}