<?php
/**
+----------------------------------------------------------------------
| swoolefy framework bases on swoole extension development, we can use it easily!
+----------------------------------------------------------------------
| Licensed ( https://opensource.org/licenses/MIT )
+----------------------------------------------------------------------
| @see https://github.com/bingcool/swoolefy
+----------------------------------------------------------------------
*/

namespace Swoolefy\Core;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\Application;
use Swoolefy\Core\AppDispatch;
use Swoolefy\Core\Controller\BController;

class HttpRoute extends AppDispatch {

    /**
     * pathInfo 模式
     * @var int
     */
    const ROUTE_MODEL_PATHINFO = ROUTE_MODEL_PATHINFO;

    /**
     * params路由模式
     * @var int
     */
    const ROUTE_MODEL_QUERY_PARAMS = ROUTE_MODEL_QUERY_PARAMS;

	/**
	 * $request 请求对象
	 * @var \Swoole\Http\Request
	 */
	public $request = null;

	/**
	 * $response
	 * @var \Swoole\Http\Response
	 */
	public $response = null;

	/**
	 * $app_conf 应用层配置值
	 * @var array
	 */
	public $app_conf = null;

    /**
     * $app 请求应用对象
     * @var App
     */
	protected $app = null;

	/**
	 * $require_uri 请求的url
	 * @var string
	 */
	protected $require_uri = null;

	/**
	 * $extend_data 额外请求数据
	 * @var null
	 */
	protected $extend_data = null;

    /**
     * @var string 控制器后缀
     */
    private $controller_suffix = 'Controller';

    /**
     * $default_route 
     * @var string
     */
    private $default_route = 'Index/index';

    /**
     * @var array
     */
    protected $actionParams = [];

    /**
	 * $deny_actions 禁止外部直接访问的action
	 * @var array
	 */
	protected static $deny_actions = ['__construct','_beforeAction','_afterAction','__destruct'];

	/**
	 * __construct
	 */
	public function __construct($extend_data = null) {
		parent::__construct();
		$this->app = Application::getApp();
		$this->request = $this->app->request;
		$this->response = $this->app->response;
		$this->app_conf = $this->app->app_conf;
		$this->require_uri = $this->request->server['PATH_INFO'];
		$this->extend_data = $extend_data;
	}

    /**
     * dispatch 路由调度
     * @return mixed
     * @throws \Exception
     */
	public function dispatch() {
	    if(!isset($this->app_conf['route_model']) || !in_array($this->app_conf['route_model'], [self::ROUTE_MODEL_PATHINFO, self::ROUTE_MODEL_QUERY_PARAMS])) {
            $this->app_conf['route_model'] = self::ROUTE_MODEL_PATHINFO;
        }
		if($this->app_conf['route_model'] == self::ROUTE_MODEL_PATHINFO) {
			if($this->require_uri == '/' || $this->require_uri == '//') {
			    if(isset($this->app_conf['default_route']) && !empty($this->app_conf['default_route'])) {
                    $this->require_uri = '/'.trim($this->app_conf['default_route'], '/');
                }else {
                	$this->require_uri = '/'.$this->default_route;
                }
			}
			$route_uri = trim($this->require_uri,'/');
			if($route_uri) {
				$route_params = explode('/', $route_uri);
				$count = count($route_params);
				switch($count) {
					case 1 : 
						$module = null;
						$controller = $route_params[0];
						$action = 'index';
					break;
					case 2 : 
						$module = null;
						// Controller/Action
						list($controller, $action) = $route_params;
					break;
					case 3 : 
						// Module/Controller/Action
						list($module, $controller, $action) = $route_params;
					break;	
				}
			}
		}else if($this->app_conf['route_model'] == self::ROUTE_MODEL_QUERY_PARAMS) {
			$module = $this->request->get['m'] ?? null;
			$controller = $this->request->get['c'] ?? 'Index';
			$action = $this->request->get['t'] ?? 'index';
			if($module) {
				$this->require_uri = '/'.$module.'/'.$controller.'/'.$action;
			}else {
				$this->require_uri = '/'.$controller.'/'.$action;
			}
		}
		// 重新设置一个route
		$this->request->server['ROUTE'] = $this->require_uri;
		// route参数组数
		$this->request->server['ROUTE_PARAMS'] = [];
		// 定义禁止直接外部访问的方法
		if(in_array($action, self::$deny_actions)) {
            $msg = "{$controller}::{$action} is not allow access action";
            $this->response->status(403);
			$this->app->beforeEnd(403, $msg);
			return false;
		}
		if($module) {
			// route参数数组
			$this->request->server['ROUTE_PARAMS'] = [3, [$module, $controller, $action]];
			// 调用
			$this->invoke($module, $controller, $action);
			
		}else {
			// route参数数组
			$this->request->server['ROUTE_PARAMS'] = [2, [$controller, $action]];
			// 调用 
			$this->invoke($module = null, $controller, $action);
		}
		return true;
	}

	/**
	 * invoke 路由与请求实例处理
	 * @param  string  $module
	 * @param  string  $controller
	 * @param  string  $action
     * @throws \Exception
	 * @return boolean
	 */
	public function invoke($module = null, $controller = null, $action = null) {
        // 匹配控制器文件
        $controller = $this->buildControllerClass($controller);
        if(!isset($this->app_conf['app_namespace'])) {
            $this->app_conf['app_namespace'] = APP_NAME;
        }
        $filePath = APP_PATH . DIRECTORY_SEPARATOR . 'Controller' . $controller . '.php';
        if($module) {
            $filePath = APP_PATH . DIRECTORY_SEPARATOR . 'Module' . DIRECTORY_SEPARATOR . $module . DIRECTORY_SEPARATOR . $controller . '.php';
            // 访问类的命名空间
            $class = $this->app_conf['app_namespace'] . '\\' . 'Module' . '\\' . $module . '\\' . $controller;
            // 不存在请求类文件
            if(!$this->isExistRouteFile($class)) {
                if(!is_file($filePath)) {
                    $targetNotFoundClassArr = $this->fileNotFound($class);
                    if(is_array($targetNotFoundClassArr)) list($class, $action) = $targetNotFoundClassArr;
                }else {
                    $this->setRouteFileMap($class);
                }
            }

        }else {
            // 访问类的命名空间
            $class = $this->app_conf['app_namespace'] . '\\' . 'Controller' . '\\' . $controller;
            if(!$this->isExistRouteFile($class)) {
                $filePath = APP_PATH . DIRECTORY_SEPARATOR . 'Controller' . DIRECTORY_SEPARATOR . $controller . '.php';
                if(!is_file($filePath)) {
                    $targetNotFoundClassArr = $this->fileNotFound($class);
                    if(is_array($targetNotFoundClassArr)) list($class, $action) = $targetNotFoundClassArr;
                }else {
                    $this->setRouteFileMap($class);
                }
            }
        }
        // reset app conf
        $this->app->setAppConf($this->app_conf);
        /**@var BController $controllerInstance */
        $controllerInstance = new $class();
        // set Controller Instance
        $this->app->setControllerInstance($controllerInstance);
        // invoke _beforeAction
        $isContinueAction = $controllerInstance->_beforeAction($action);
        if($isContinueAction === false) {
            $this->response->status(403);
            $this->response->header('Content-Type', 'application/json; charset=UTF-8');
            $queryString = isset($this->request->server['QUERY_STRING']) ? '?' . $this->request->server['QUERY_STRING'] : '';
            if(isset($this->request->post) && !empty($this->request->post)) {
                $post = json_encode($this->request->post, JSON_UNESCAPED_UNICODE);
                $errorMsg = "Call {$class}::_beforeAction() return false, forbidden continue call {$class}::{$action}, please checkout it ||| " . $this->request->server['REQUEST_URI'] . $queryString . ' ||| ' . $post;
            }else {
                $errorMsg = "Call {$class}::_beforeAction() return false, forbidden continue call {$class}::{$action}, please checkout it ||| " . $this->request->server['REQUEST_URI'] . $queryString;
            }
            $this->app->beforeEnd(403, $errorMsg);
            return false;
        }
        // 创建reflector对象实例
        $reflector = new \ReflectionClass($controllerInstance);
        if($reflector->hasMethod($action)) {
            list($method, $args) = $this->bindActionParams($controllerInstance, $action, $this->buildParams());
            if($method->isPublic() && !$method->isStatic()) {
                try{
                    $controllerInstance->{$action}(...$args);
                    $controllerInstance->_afterAction($action);
                }catch (\Throwable $t) {
                    $queryString = isset($this->request->server['QUERY_STRING']) ? '?' . $this->request->server['QUERY_STRING'] : '';
                    if(isset($this->request->post) && !empty($this->request->post)) {
                        $post = json_encode($this->request->post, JSON_UNESCAPED_UNICODE);
                        $errorMsg = $t->getMessage() . ' on ' . $t->getFile() . ' on line ' . $t->getLine() . ' ||| ' . $this->request->server['REQUEST_URI'] . $queryString . ' ||| ' . $post;
                    }else {
                        $errorMsg = $t->getMessage() . ' on ' . $t->getFile() . ' on line ' . $t->getLine() . ' ||| ' . $this->request->server['REQUEST_URI'] . $queryString;
                    }
                    // 记录错误异常
                    $this->response->status(500);
                    $exceptionClass = $this->app->getExceptionClass();
                    $this->app->beforeEnd(500, $errorMsg);
                    $exceptionClass::shutHalt($errorMsg);
                    return false;
                }
            }else {
                $errorMsg = "Class method {$class}::{$action} is protected or private property, can't be called by Controller Instance";
                $this->response->status(500);
                $this->app->beforeEnd(500, $errorMsg);
                return false;
            }
        }else {
            $errorMsg = "Controller file is exited, but call undefined {$class}::{$action} method";
            $this->response->status(404);
            $this->response->header('Content-Type', 'application/json; charset=UTF-8');
            $this->app->beforeEnd(404, $errorMsg);
            return false;
        }
    }

	/**
	 * redirectNotFound 重定向至NotFound类
	 * @return   array
	 */
	public function redirectNotFound($call_func = null) {
		if(isset($this->app_conf['not_found_handler'])) {
			// 重定向至NotFound类
			list($namespace, $action) = $this->app_conf['not_found_handler'];
			$route_params = explode('\\', $namespace);
			if(is_array($route_params)) {
				$controller = array_pop($route_params);
			}
            // 重新设置一个NotFound类的route
            $this->request->server['ROUTE'] = '/'.$controller.'/'.$action;
            $class = trim(str_replace('/', '\\', $namespace.$this->controller_suffix), '/');
            return [$class, $action];
		}
	}

    /**
     * @param string $controller
     * @return string
     */
    protected function buildControllerClass(string $controller) {
        return $controller.$this->controller_suffix;
    }

	/**
	 * isExistRouteFile 判断是否存在请求的route文件
	 * @param    string  $route  请求的路由uri
	 * @return   boolean
	 */
	public function isExistRouteFile($route) {
		return isset(self::$routeCacheFileMap[$route]) ? self::$routeCacheFileMap[$route] : false;
	}

	/**
	 * setRouteFileMap 缓存路由的映射
	 * @param   string  $route  请求的路由uri
	 * @return  void
	 */
	public function setRouteFileMap($route) {
		self::$routeCacheFileMap[$route] = true;
	}

    /**
     * @param $class
     * @return array|bool
     */
	protected function fileNotFound($class) {
        $this->response->status(404);
        $this->response->header('Content-Type', 'application/json; charset=UTF-8');
        // 使用配置的NotFound类
        if(isset($this->app_conf['not_found_handler']) && is_array($this->app_conf['not_found_handler'])) {
            return $this->redirectNotFound();
        }else {
            $msg = "Class {$class} is not exit";
            $this->app->beforeEnd(404, $msg);
            return false;
        }
    }

	/**
	 * resetRouteDispatch 重置路由调度,将实际的路由改变请求,主要用在bootstrap()中
	 * @param   string  $route  请求的路由uri
	 * @return  void
	 */
	public static function resetRouteDispatch($route) {
	    $route = trim($route,'/');
        Application::getApp()->request->server['PATH_INFO'] = '/'.$route;
    }

    /**
     * @param $controllerInstance
     * @param $action
     * @param $params
     * @return array
     * @throws \ReflectionException
     */
    protected function bindActionParams($controllerInstance, $action, $params) {
        $method = new \ReflectionMethod($controllerInstance, $action);
        $args = [];
        $missing = [];
        $actionParams = [];
        foreach ($method->getParameters() as $param) {
            $name = $param->getName();
            if (array_key_exists($name, $params)) {
                $isValid = true;
                if ($param->isArray()) {
                    $params[$name] = (array)$params[$name];
                } elseif (is_array($params[$name])) {
                    $isValid = false;
                } elseif (
                    PHP_VERSION_ID >= 70000 &&
                    ($type = $param->getType()) !== null &&
                    $type->isBuiltin() &&
                    ($params[$name] !== null || !$type->allowsNull())
                ) {
                    $typeName = PHP_VERSION_ID >= 70100 ? $type->getName() : (string)$type;
                    switch($typeName) {
                        case 'int':
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
                if(!$isValid) {
                    throw new \Exception("Invalid data received for parameter of {$name}".'|||'.$this->request->server['REQUEST_URI']);
                }
                $args[] = $actionParams[$name] = $params[$name];
                unset($params[$name]);
            }elseif ($param->isDefaultValueAvailable()) {
                $args[] = $actionParams[$name] = $param->getDefaultValue();
            }else {
                $missing[] = $name;
            }
        }

        if(!empty($missing)) {
            throw new \Exception("Missing required parameters of name : ".implode(', ', $missing).'|||'.$this->request->server['REQUEST_URI'].'|||'.json_encode($actionParams, JSON_UNESCAPED_UNICODE));
        }

        $this->actionParams = $actionParams;

        return [$method, $args];
    }

    /**
     * @return array|mixed
     */
    protected function buildParams() {
        $get = isset($this->request->get) ? $this->request->get : [];
        $post = isset($this->request->post) ? $this->request->post : [];
        if(empty($post)) {
            $post = json_decode($this->request->rawContent(), true) ?? [];
        }
        $params = $get + $post;
        return $params;
    }

    /**
     * __destruct
     */
    public function __destruct() {
        unset($this->app);
    }
}