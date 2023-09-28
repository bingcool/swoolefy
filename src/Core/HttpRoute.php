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
     * $extendData 额外请求数据
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
    protected $groupMiddleware = [];

    /**
     * @var array
     */
    protected $beforeMiddleware = [];

    /**
     * @var array|mixed
     */
    protected $afterMiddleware = [];

    /**
     * @var array
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
        list($this->groupMiddleware, $this->beforeMiddleware, $this->dispatchRoute, $this->afterMiddleware, $this->routeMethod, $this->groupMeta)  = self::getHttpRouterMapUri($this->requestInput->getServerParams('PATH_INFO'));
    }

    /**
     * dispatch
     * @return mixed
     * @throws \Throwable
     */
    public function dispatch()
    {
        $method = $this->requestInput->getMethod();
        if ($this->routeMethod != 'ANY' && !in_array($method, $this->routeMethod)) {
            $routeMethods = implode(',', $this->routeMethod);
            throw new DispatchException("Not Allow Route Method.You should use [$routeMethods] method.");
        }

        if (!isset($this->appConf['app_namespace']) || $this->appConf['app_namespace'] != APP_NAME ) {
            $this->appConf['app_namespace'] = APP_NAME;
        }

        $controllerNamespace = $this->dispatchRoute[0];
        $action = $this->dispatchRoute[1];

        $dispatchRouteItem = explode("\\", $controllerNamespace);
        $count = count($dispatchRouteItem);
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

        if ($module) {
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

        /**@var BController $controllerInstance */
        $controllerInstance = new $class();
        // set Controller Instance
        $this->app->setControllerInstance($controllerInstance);

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
                $action
            );

            throw new DispatchException($errorMsg, 404);
        }
        // reflector params handle
        list($method, $args) = $this->bindActionParams($controllerInstance, $action, $this->requestInput->all());
        $controllerInstance->{$action}(...$args);
        $controllerInstance->_afterAction($action);
        $extendData = $controllerInstance->getExtendData();
        $this->requestInput->setExtendData($extendData);
        $this->handleAfterRouteMiddles();
        return true;
    }

    /**
     * group middles
     *
     * @return void
     */
    private function handleGroupRouteMiddles()
    {
        foreach ($this->groupMiddleware as $middleware) {
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
        foreach($this->beforeMiddleware as $middleware) {
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
        foreach ($this->afterMiddleware as $middleware) {
            try {
                if ($middleware instanceof \Closure) {
                    call_user_func($middleware, $this->requestInput, $this->responseOutput);
                }else if (class_exists($middleware)) {
                    $middlewareEntity = new $middleware;
                    if ($middlewareEntity instanceof RouteMiddleware) {
                        $middlewareEntity->handle($this->requestInput, $this->responseOutput);
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
        Application::getApp()->request->server['PATH_INFO'] = DIRECTORY_SEPARATOR . $route;
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
            $groupMiddleware = $routerMap[$uri]['group_meta']['middleware'] ?? [];
            $method = $routerMap[$uri]['method'];
            if(!isset($routerMeta['dispatch_route'])) {
                $routerMeta['dispatch_route'] = $uri;
            }else {
                $originDispatchRoute = $routerMeta['dispatch_route'];
                $dispatchRoute = str_replace("\\",DIRECTORY_SEPARATOR, $routerMeta['dispatch_route'][0]);
                $dispatchRouteItems = explode(DIRECTORY_SEPARATOR, $dispatchRoute);
                $itemNum = count($dispatchRouteItems);
                if(!in_array($itemNum, [static::ITEM_NUM_3, static::ITEM_NUM_5])) {
                    throw new DispatchException("Dispatch Route Class Error");
                }
            }

            $beforeMiddleware = [];

            foreach($routerMeta as $alias => $handle) {
                if ($alias != 'dispatch_route') {
                    $beforeMiddleware[] = $handle;
                    unset($routerMeta[$alias]);
                    continue;
                }
                unset($routerMeta[$alias]);
                break;
            }

            $afterMiddleware = array_values($routerMeta);
            $routeCacheItems = [$groupMiddleware, $beforeMiddleware, $originDispatchRoute, $afterMiddleware, $method, $groupMeta];
            self::$routeCache[$uri] = $routeCacheItems;
            unset($routerMap[$uri]);
            return $routeCacheItems;
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