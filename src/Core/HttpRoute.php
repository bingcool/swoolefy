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

use Swoolefy\Core\Controller\BController;
use Swoolefy\Exception\DispatchException;

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
     * $request
     * @var \Swoole\Http\Request
     */
    public $request = null;

    /**
     * $response
     * @var \Swoole\Http\Response
     */
    public $response = null;

    /**
     * $appConf
     * @var array
     */
    public $appConf = [];

    /**
     * $app
     * @var App
     */
    protected $app = null;

    /**
     * $requireUri
     * @var string
     */
    protected $routerUri = null;

    /**
     * $extendData
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
     * $denyActions
     * @var array
     */
    protected static $denyActions = ['__construct', '_beforeAction', '_afterAction', '__destruct'];

    /**
     * __construct
     * @param mixed $extendData
     */
    public function __construct($extendData = null)
    {
        parent::__construct();
        $this->app        = Application::getApp();
        $this->request    = $this->app->request;
        $this->response   = $this->app->response;
        $this->appConf    = $this->app->appConf;
        $this->routerUri  = Swfy::getRouterMapUri($this->request->server['PATH_INFO']);
        $this->extendData = $extendData;
    }

    /**
     * dispatch
     * @return mixed
     * @throws \Throwable
     */
    public function dispatch()
    {
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
            $module     = $this->request->get['m'] ?? null;
            $controller = $this->request->get['c'] ?? 'Index';
            $action     = $this->request->get['t'] ?? 'index';

            if ($module) {
                $this->routerUri = DIRECTORY_SEPARATOR . $module . DIRECTORY_SEPARATOR . $controller . DIRECTORY_SEPARATOR . $action;
            } else {
                $this->routerUri = DIRECTORY_SEPARATOR . $controller . DIRECTORY_SEPARATOR . $action;
            }
        }
        // reset route
        $this->request->server['ROUTE'] = $this->routerUri;
        // route params array attach to server
        $this->request->server['ROUTE_PARAMS'] = [];
        // forbidden call action
        if (in_array($action, static::$denyActions)) {
            $errorMsg = "{$controller}::{$action} is not allow access action";
            throw new DispatchException($errorMsg, 403);
        }

        if ($module) {
            // route params array
            $this->request->server['ROUTE_PARAMS'] = [3, [$module, $controller, $action]];
            $this->invoke($module, $controller, $action);

        } else {
            // route params array
            $this->request->server['ROUTE_PARAMS'] = [2, [$controller, $action]];
            $this->invoke($module = null, $controller, $action);
        }

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
    ) {
        $controller = $this->buildControllerClass($controller);
        if ($module) {
            $filePath = APP_PATH . DIRECTORY_SEPARATOR . 'Module' . DIRECTORY_SEPARATOR . $module . DIRECTORY_SEPARATOR . $controller . '.php';
            $class    = $this->appConf['app_namespace'] . '\\' . 'Module' . '\\' . $module . '\\' . $controller;

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
        // invoke _beforeAction
        $isContinueAction = $controllerInstance->_beforeAction($action);

        if (isset($this->appConf['enable_action_prefix']) && $this->appConf['enable_action_prefix']) {
            $targetAction = $this->actionPrefix . ucfirst($action);
        } else {
            $targetAction = $action;
        }

        if ($this->app->isEnd()) {
            return false;
        }

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
            list($method, $args) = $this->bindActionParams($controllerInstance, $targetAction, $this->buildParams());
            if ($method->isPublic() && !$method->isStatic()) {
                $controllerInstance->{$targetAction}(...$args);
                $controllerInstance->_afterAction($action);
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
    }

    /**
     * redirectNotFound 重定向至NotFound类
     * @return array
     */
    public function redirectNotFound()
    {
        if (isset($this->appConf['not_found_handler'])) {
            // reset NotFound class
            list($namespace, $action) = $this->appConf['not_found_handler'];
            $route_params = explode('\\', $namespace);
            if (is_array($route_params)) {
                $controller = array_pop($route_params);
            }
            // reset NotFound class route
            $this->request->server['ROUTE'] = DIRECTORY_SEPARATOR . $controller . DIRECTORY_SEPARATOR . $action;
            $class = trim(str_replace(DIRECTORY_SEPARATOR, '\\', $namespace . $this->controllerSuffix), DIRECTORY_SEPARATOR);
            return [$class, $action];
        }
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
    public function isExistRouteFile(string $route)
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
     * @param string $class
     * @return array
     */
    protected function fileNotFound(string $class)
    {
        if (isset($this->appConf['not_found_handler']) && is_array($this->appConf['not_found_handler'])) {
            return $this->redirectNotFound();
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
     * @throws \ReflectionException
     */
    protected function bindActionParams($controllerInstance, $action, $params)
    {
        $method = new \ReflectionMethod($controllerInstance, $action);
        $args = $missing = $actionParams = [];

        foreach ($method->getParameters() as $param) {
            $name = $param->getName();
            if (array_key_exists($name, $params)) {
                $isValid = true;
                if ($param->isArray()) {
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
                    throw new DispatchException("Invalid data received for parameter of {$name}" . '|||' . $this->request->server['REQUEST_URI']);
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
            throw new DispatchException("Missing required parameters of name : " . implode(', ', $missing) . '|||' . $this->request->server['REQUEST_URI'] . '|||' . json_encode($actionParams, JSON_UNESCAPED_UNICODE));
        }

        $this->actionParams = $actionParams;

        return [$method, $args];
    }

    /**
     * @return array
     */
    protected function buildParams()
    {
        $get  = isset($this->request->get) ? $this->request->get : [];
        $post = isset($this->request->post) ? $this->request->post : [];
        if (empty($post)) {
            $post = json_decode($this->request->rawContent(), true) ?? [];
        }
        $params = $get + $post;
        return $params;
    }

    /**
     * __destruct
     */
    public function __destruct()
    {
        unset($this->app, $this->request, $this->response);
    }
}