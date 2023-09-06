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

namespace Swoolefy\Core;

use Swoolefy\Http\Route;
use Swoolefy\Http\RequestInput;
use Swoolefy\Http\ResponseOutput;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Exception\DispatchException;
use Swoolefy\Exception\SystemException;

class HttpRoute extends AppDispatch
{

    /**
     * pathInfo model
     * @var int
     */
    const ROUTE_MODEL_PATHINFO = ROUTE_MODEL_PATHINFO;

    /**
     * params model
     * @var int
     */
    const ROUTE_MODEL_QUERY_PARAMS = ROUTE_MODEL_QUERY_PARAMS;

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
     * @var string
     */
    protected $routerUri = null;

    /**
     * $extendData 额外请求数据
     * @var mixed
     */
    protected $extendData = null;

    /**
     * @var string
     */
    private $controllerSuffix = 'Controller';

    /**
     * $defaultRoute
     * @var string
     */
    private $defaultRoute = 'Index/index';

    /**
     * actionPrefix
     * @var string
     */
    private $actionPrefix = 'action';

    /**
     * @var array
     */
    protected $actionParams = [];

    /**
     * @var array
     */
    protected $middleware = [];

    /**
     * @var array
     */
    protected $beforeHandle = [];

    /**
     * @var array|mixed
     */
    protected $afterHandle = [];

    /**
     * @var string
     */
    protected $routeMethod;

    /**
     * @var array
     */
    protected $groupMeta;

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
        list($this->middleware, $this->beforeHandle, $this->routerUri, $this->afterHandle, $this->routeMethod, $this->groupMeta)  = self::getHttpRouterMapUri($this->requestInput->getServerParams('PATH_INFO'));
    }

    /**
     * dispatch
     * @return mixed
     * @throws \Throwable
     */
    public function dispatch()
    {
        $method = $this->requestInput->getMethod();
        if ($this->routeMethod != 'ANY' && $method != $this->routeMethod) {
            throw new DispatchException("Not Allow Route Method.You should use [$this->routeMethod] method.");
        }

        if (!isset($this->appConf['route_model']) || !in_array($this->appConf['route_model'], [self::ROUTE_MODEL_PATHINFO, self::ROUTE_MODEL_QUERY_PARAMS])) {
            $this->appConf['route_model'] = self::ROUTE_MODEL_PATHINFO;
        }

        if (!isset($this->appConf['app_namespace']) || $this->appConf['app_namespace'] != APP_NAME ) {
            $this->appConf['app_namespace'] = APP_NAME;
        }

        if ($this->appConf['route_model'] == self::ROUTE_MODEL_PATHINFO) {
            if ($this->routerUri == DIRECTORY_SEPARATOR || $this->routerUri == '//') {
                if (isset($this->appConf['default_route']) && !empty($this->appConf['default_route'])) {
                    $this->routerUri = DIRECTORY_SEPARATOR . trim($this->appConf['default_route'], DIRECTORY_SEPARATOR);
                } else {
                    $this->routerUri = DIRECTORY_SEPARATOR . $this->defaultRoute;
                }
            }

            $routeUri = trim($this->routerUri, DIRECTORY_SEPARATOR);

            if ($routeUri) {
                $routeParams = explode(DIRECTORY_SEPARATOR, $routeUri);
                $count = count($routeParams);
                switch ($count) {
                    case 1 :
                        $module = null;
                        $controller = $routeParams[0];
                        $action = 'index';
                        break;
                    case 2 :
                        $module = null;
                        // Controller/Action
                        list($controller, $action) = $routeParams;
                        break;
                    case 3 :
                        // Module/Controller/Action
                        list($module, $controller, $action) = $routeParams;
                        break;
                }
            }

        } else if ($this->appConf['route_model'] == self::ROUTE_MODEL_QUERY_PARAMS) {
            $module     = $this->requestInput->getQueryParams('m') ?? null;
            $controller = $this->requestInput->getQueryParams('c') ?? 'Index';
            $action     = $this->requestInput->getQueryParams('t') ?? 'index';
            if ($module) {
                $this->routerUri = DIRECTORY_SEPARATOR . $module . DIRECTORY_SEPARATOR . $controller . DIRECTORY_SEPARATOR . $action;
            } else {
                $this->routerUri = DIRECTORY_SEPARATOR . $controller . DIRECTORY_SEPARATOR . $action;
            }
        }

        // forbidden call action
        if (in_array($action, static::$denyActions)) {
            $errorMsg = "{$controller}::{$action} is not allow access action";
            throw new DispatchException($errorMsg, 403);
        }

        if ($module) {
            // route params array
            $routeParams = [3, [$module, $controller, $action]];
            $this->invoke($module, $controller, $action);
        } else {
            // route params array
            $routeParams = [2, [$controller, $action]];
            $this->invoke($module = null, $controller, $action);
        }

        // route params array attach to server
        $this->requestInput->getSwooleRequest()->server['ROUTE'] = $this->routerUri;
        $this->requestInput->getSwooleRequest()->server['ROUTE_PARAMS'] = $routeParams;

        return true;
    }

    /**
     * invoke router instance
     * @param string $module
     * @param string $controller
     * @param string $action
     * @return bool
     * @throws \Throwable
     */
    protected function invoke(
        ?string $module = null,
        ?string $controller = null,
        ?string $action = null
    ): bool
    {
        $controller = $this->buildControllerClass($controller);
        if ($module) {
            $filePath = APP_PATH . DIRECTORY_SEPARATOR . 'Module' . DIRECTORY_SEPARATOR . $module.DIRECTORY_SEPARATOR.'Controller' . DIRECTORY_SEPARATOR . $controller . '.php';
            $class    = $this->appConf['app_namespace'] . '\\' . 'Module' . '\\' . $module .'\\'.'Controller'. '\\' . $controller;
            if (!$this->isExistRouteFile($class)) {
                if (!is_file($filePath)) {
                    $targetNotFoundClassArr = $this->fileNotFound($class);
                    if (is_array($targetNotFoundClassArr)) list($class, $action) = $targetNotFoundClassArr;
                } else {
                    $this->setRouteFileMap($class);
                }
            }

        } else {
            $class = $this->appConf['app_namespace'] . '\\' . 'Controller' . '\\' . $controller;
            if (!$this->isExistRouteFile($class)) {
                $filePath = APP_PATH . DIRECTORY_SEPARATOR . 'Controller' . DIRECTORY_SEPARATOR . $controller . '.php';
                if (!is_file($filePath)) {
                    $targetNotFoundClassArr = $this->fileNotFound($class);
                    if (is_array($targetNotFoundClassArr)) list($class, $action) = $targetNotFoundClassArr;
                } else {
                    $this->setRouteFileMap($class);
                }
            }
        }
        // reset app conf
        $this->app->setAppConf($this->appConf);

        /**@var BController $controllerInstance */
        $controllerInstance = new $class();
        // set Controller Instance
        $this->app->setControllerInstance($controllerInstance);

        if (isset($this->appConf['enable_action_prefix']) && $this->appConf['enable_action_prefix']) {
            $targetAction = $this->actionPrefix . ucfirst($action);
        } else {
            $targetAction = $action;
        }

        if ($this->app->isEnd()) {
            throw new SystemException('System Request End Error', 500);
        }

        // handle route group middles
        $this->handleGroupRouteMiddles();

        // handle before route middles
        $this->handleBeforeRouteMiddles();

        // set extend data
        $controllerInstance->setExtendData($this->requestInput->getExtendData());
        // invoke _beforeAction
        $isContinueAction = $controllerInstance->_beforeAction($action);

        if ($isContinueAction === false) {
            $errorMsg = sprintf(
                "Call %s::_beforeAction() return false, forbidden continue call %s::%s",
                $class,
                $class,
                $targetAction
            );

            throw new DispatchException($errorMsg, 404);
        }

        // reflector object
        $reflector = new \ReflectionClass($controllerInstance);
        if ($reflector->hasMethod($targetAction)) {
            list($method, $args) = $this->bindActionParams($controllerInstance, $targetAction, $this->requestInput->getRequestParams());
            if ($method->isPublic() && !$method->isStatic()) {
                $controllerInstance->{$targetAction}(...$args);
                $controllerInstance->_afterAction($action);
                $this->handleAfterRouteMiddles();
            } else {
                $errorMsg = sprintf(
                    "Class method %s::%s is protected or private property, can't be called by Controller Instance",
                    $class,
                    $targetAction
                );

                throw new DispatchException($errorMsg, 500);
            }
        } else {
            $errorMsg = sprintf(
                "Call undefined %s::%s method",
                $class,
                $targetAction
            );

            throw new DispatchException($errorMsg, 404);
        }

        return true;
    }

    /**
     * @param string $controller
     * @return string
     */
    protected function buildControllerClass(string $controller)
    {
        return $controller . $this->controllerSuffix;
    }

    /**
     * isExistRouteFile 判断是否存在请求的route文件
     * @param string $route 请求的路由uri
     * @return bool
     */
    public function isExistRouteFile(string $route): bool
    {
        return isset(self::$routeCacheFileMap[$route]) ? self::$routeCacheFileMap[$route] : false;
    }

    /**
     * setRouteFileMap
     * @param string $route
     * @return void
     */
    public function setRouteFileMap(string $route)
    {
        self::$routeCacheFileMap[$route] = true;
    }

    /**
     * group middles
     *
     * @return void
     */
    private function handleGroupRouteMiddles()
    {
        foreach ($this->middleware as $middlewareHandle) {
            if (class_exists($middlewareHandle)) {
                $middlewareHandleEntity = new $middlewareHandle;
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
        foreach($this->beforeHandle as $handle) {
            if ($handle instanceof \Closure) {
                $result = call_user_func($handle, $this->requestInput, $this->responseOutput);
                if ($result === false) {
                    throw new SystemException('beforeHandle route middle return false, Not Allow Coroutine To Next Middle', 500);
                }
            }else if (class_exists($handle)) {
                $handleEntity = new $handle;
                if ($handleEntity instanceof RouteMiddleware) {
                    $handleEntity->handle($this->requestInput, $this->responseOutput);
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
        foreach ($this->afterHandle as $handle) {
            try {
                if ($handle instanceof \Closure) {
                    call_user_func($handle, $this->requestInput, $this->responseOutput);
                }else if (class_exists($handle)) {
                    $handleEntity = new $handle;
                    if ($handleEntity instanceof RouteMiddleware) {
                        $handleEntity->handle($this->requestInput, $this->responseOutput);
                    }
                }
            }catch (\Throwable $exception) {
                // todo
            }
        }
    }

    /**
     * @param string $class
     * @return array
     */
    protected function fileNotFound(string $class): array
    {
        if (isset($this->appConf['not_found_handler']) && is_array($this->appConf['not_found_handler'])) {
            // reset NotFound class
            list($namespace, $action) = $this->appConf['not_found_handler'];
            $routeParams = explode('\\', $namespace);
            if (is_array($routeParams)) {
                $controller = array_pop($routeParams);
            }
            // reset NotFound class route
            $this->requestInput->getRequest()->server['ROUTE'] = DIRECTORY_SEPARATOR . $controller . DIRECTORY_SEPARATOR . $action;
            $class = trim(str_replace(DIRECTORY_SEPARATOR, '\\', $namespace . $this->controllerSuffix), DIRECTORY_SEPARATOR);
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
        Application::getApp()->request->server['PATH_INFO'] = DIRECTORY_SEPARATOR . $route;
    }

    /**
     * @param $controllerInstance
     * @param $action
     * @param $params
     * @return array
     * @throws DispatchException
     */
    protected function bindActionParams(object $controllerInstance, string $action, mixed $params): array
    {
        $method = new \ReflectionMethod($controllerInstance, $action);
        $args = $missing = $actionParams = [];

        foreach ($method->getParameters() as $param) {
            $name = $param->getName();
            if (array_key_exists($name, $params)) {
                $isValid = true;
                if ($param->hasType() && $param->getType()->getName() == 'array') {
                    $params[$name] = (array)$params[$name];
                } elseif (is_array($params[$name])) {
                    $isValid = false;
                } elseif (
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
            } elseif ($param->isDefaultValueAvailable()) {
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
    protected static function getHttpRouterMapUri(string $uri): array
    {
        $uri = DIRECTORY_SEPARATOR.trim($uri,DIRECTORY_SEPARATOR);

        if (isset(self::$routeCache[$uri])) {
            return self::$routeCache[$uri];
        }

        $routerMap = Route::loadRouteFile();

        if (isset($routerMap[$uri]['route_meta'])) {
            $groupMeta  = $routerMap[$uri]['group_meta'] ?? [];
            $routerMeta = $routerMap[$uri]['route_meta'];
            $middleware = $routerMap[$uri]['group_meta']['middleware'] ?? [];
            $method = $routerMap[$uri]['method'];
            if(!isset($routerMeta['dispatch_route'])) {
                $routerMeta['dispatch_route'] = $uri;
            }else {
                $dispatchRoute = str_replace("\\",DIRECTORY_SEPARATOR, $routerMeta['dispatch_route'][0]);
                $dispatchRouteItems = explode(DIRECTORY_SEPARATOR, $dispatchRoute);
                $itemNum = count($dispatchRouteItems);
                if(!in_array($itemNum, [3,5])) {
                    throw new DispatchException("Dispatch Route Class Error");
                }

                if($itemNum == 3) {
                    $controllerName = substr($dispatchRouteItems[2], 0 ,strlen($dispatchRouteItems[2]) - strlen('Controller'));
                    $routeUri = DIRECTORY_SEPARATOR.$controllerName.DIRECTORY_SEPARATOR.$routerMeta['dispatch_route'][1];
                }else if($itemNum == 5) {
                    $moduleName = $dispatchRouteItems[2];
                    $controllerName = substr($dispatchRouteItems[4], 0 ,strlen($dispatchRouteItems[4]) - strlen('Controller'));
                    $routeUri = DIRECTORY_SEPARATOR.$moduleName.DIRECTORY_SEPARATOR.$controllerName.DIRECTORY_SEPARATOR.$routerMeta['dispatch_route'][1];
                }
            }

            $beforeHandle = [];

            foreach($routerMeta as $alias => $handle) {
                if ($alias != 'dispatch_route') {
                    $beforeHandle[] = $handle;
                    unset($routerMeta[$alias]);
                    continue;
                }
                unset($routerMeta[$alias]);
                break;
            }

            $afterHandle = array_values($routerMeta);
            $routeCache = [$middleware, $beforeHandle, $routeUri, $afterHandle, $method, $groupMeta];
            self::$routeCache[$uri] = $routeCache;
            unset($routerMap[$uri]);
            return $routeCache;

        }else {
            throw new DispatchException("Not Found Route [$uri].");
        }
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