<?php
/**
+----------------------------------------------------------------------
| swoolefy framework bases on swoole extension development, we can use it easily!
+----------------------------------------------------------------------
| Licensed ( https://opensource.org/licenses/MIT )
+----------------------------------------------------------------------
| Author: bingcool <bingcoolhuang@gmail.com || 2437667702@qq.com>
+----------------------------------------------------------------------
*/

namespace Swoolefy\Core;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\Application;
use Swoolefy\Core\AppDispatch;

class HttpRoute extends AppDispatch {
	/**
	 * $request 请求对象
	 * @var null
	 */
	public $request = null;

	/**
	 * $response
	 * @var null
	 */
	public $response = null;

	/**
	 * $require_uri 请求的url
	 * @var null
	 */
	public $require_uri = null;

	/**
	 * $config 配置值
	 * @var null
	 */
	public $config = null;

	/**
	 * $extend_data 额外请求数据
	 * @var null
	 */
	public $extend_data = null;

    /**
     * @var int pathinfo 模式
     */
    private $route_model_pathinfo = 1;

    /**
     * @var int 参数路由模式
     */
    private $route_model_query_params = 2;

    /**
     * @var string 控制器后缀
     */
    private $controller_suffix = 'Controller';

    /**
	 * $deny_actions 禁止外部直接访问的action
	 * @var array
	 */
	public static $deny_actions = ['__construct','_beforeAction','_afterAction','__destruct'];

	/**
	 * __construct
	 */
	public function __construct($extend_data = null) {
		// 执行父类
		parent::__construct();
		// 获取请求对象
		$this->request = Application::getApp()->request;
		$this->require_uri = $this->request->server['PATH_INFO'];

		$this->response = Application::getApp()->response;
		$this->config = Application::getApp()->config;

		$this->extend_data = $extend_data;
	}

	/**
	 * dispatch 路由调度
	 * @return mixed
	 */
	public function dispatch() {
	    if(!isset($this->config['route_model']) || !in_array($this->config['route_model'], [$this->route_model_pathinfo, $this->route_model_query_params])) {
            $this->config['route_model'] = 1;
        }
		// pathinfo的模式
		if($this->config['route_model'] == $this->route_model_pathinfo) {
			// 采用默认路由
			if($this->require_uri == '/' || $this->require_uri == '//') {
			    if(isset($this->config['default_route'])) {
                    $this->require_uri = '/'.$this->config['default_route'];
                }
			}
			$route_uri = trim($this->require_uri,'/');
			if($route_uri) {
				// 分割出route
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
						// Controller/Action模式
						list($controller, $action) = $route_params;
					break;
					case 3 : 
						// Module/Controller/Action模式
						list($module, $controller, $action) = $route_params;
					break;	
				}
			}
		}else if($this->config['route_model'] == $this->route_model_query_params) {
			$module = (isset($this->request->get['m']) && !$this->request->get['m']) ? $this->request->get['m'] : null;
			$controller = $this->request->get['c'];
			$action = isset($this->request->get['t']) ? $this->request->get['t'] : 'index';
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
			return $this->response->end("{$action}() method is not be called");
		}
		if($module) {
			// route参数数组
			$this->request->server['ROUTE_PARAMS'] = [3, [$module,$controller,$action]];
			// 调用
			$this->invoke($module, $controller, $action);
			
		}else {
			// route参数数组
			$this->request->server['ROUTE_PARAMS'] = [2, [$controller,$action]];
			// 调用 
			$this->invoke($module = null, $controller, $action);
		}
		return null;
	}

	/**
	 * invoke 路由与请求实例处理
	 * @param  string  $module
	 * @param  string  $controller
	 * @param  string  $action
	 * @return boolean
	 */
	public function invoke($module = null, $controller = null, $action = null) {
		// 匹配控制器文件
		$controller = $controller.$this->controller_suffix;
		if(!isset($this->config['app_namespace'])) {
            $this->config['app_namespace'] = APP_NAME;
        }
		// 判断是否存在这个类文件
		if($module) {
			// 访问类的命名空间
			$class = $this->config['app_namespace'].'\\'.'Module'.'\\'.$module.'\\'.$controller;
			// 不存在请求类文件
			if(!self::isExistRouteFile($class)) {
				$filePath = APP_PATH.DIRECTORY_SEPARATOR.'Module'.DIRECTORY_SEPARATOR.$module.DIRECTORY_SEPARATOR.$controller.'.php';
				if(!is_file($filePath)) {
					$this->response->status(404);
					$this->response->header('Content-Type','application/json; charset=UTF-8');
                    // 使用配置的NotFound类
                    if(isset($this->config['not_found_handle']) && is_array($this->config['not_found_handle'])) {
                        // 访问类的命名空间
                        $class = $this->redirectNotFound();
                    }else {
                        return $this->response->end(json_encode([
                            'ret'=> 404,
                            'msg'=> $filePath.' is not exit!',
                            'data'=>''
                        ]));
                    }
				}else {
					self::setRouteFileMap($class);
				}
			}

		}else {
			// 访问类的命名空间
			$class = $this->config['app_namespace'].'\\'.'Controller'.'\\'.$controller;
			// 不存在请求类文件
			if(!self::isExistRouteFile($class)) {
				$filePath = APP_PATH.DIRECTORY_SEPARATOR.'Controller'.DIRECTORY_SEPARATOR.$controller.'.php';
				if(!is_file($filePath)) {
					$this->response->status(404);
					$this->response->header('Content-Type','application/json; charset=UTF-8');
                    // 使用配置的NotFound类
                    if(isset($this->config['not_found_handle']) && is_array($this->config['not_found_handle'])) {
                        // 访问类的命名空间
                        $class = $this->redirectNotFound();
                    }else {
                        return $this->response->end(json_encode([
                            'ret'=> 404,
                            'msg'=> $filePath.' is not exit!',
                            'data'=>''
                        ]));
                    }
				}else {
					self::setRouteFileMap($class);
				}
			}
		}

		// 创建控制器实例
		$controllerInstance = new $class();
		// 提前执行_beforeAction函数
		if($controllerInstance->_beforeAction() === false || is_null($controllerInstance->_beforeAction())) {
			$this->response->status(403);
			$this->response->header('Content-Type','application/json; charset=UTF-8');
			return $this->response->write(json_encode([
				'ret'=> 403,
				'msg'=> 'if you called _beforeAction() must return true then can exec action function,otherwise this request is end when in _beforeAction()',
				'data'=>''
			]));
		}
		// 创建reflector对象实例
		$reflector = new \ReflectionClass($controllerInstance);
		// 如果存在该类和对应的方法
		if($reflector->hasMethod($action)) {
			$method = new \ReflectionMethod($controllerInstance, $action);
			if($method->isPublic() && !$method->isStatic()) {
				try{
					if($this->extend_data) {
						$method->invoke($controllerInstance, $this->extend_data);
					}else {
						$method->invoke($controllerInstance);
					}
           	 		
		        }catch (\ReflectionException $e) {
		            // 方法调用发生异常后 引导到__call方法处理
		            $method = new \ReflectionMethod($controllerInstance, '__call');
		            $method->invokeArgs($controllerInstance, array($action, ''));

		        }catch(\Throwable $t) {
				    $query_string = isset($this->request->server['QUERY_STRING']) ? '?'.$this->request->server['QUERY_STRING'] : '';
				    if(isset($this->request->post) && !empty($this->request->post)) {
				        $post = json_encode($this->request->post,JSON_UNESCAPED_UNICODE);
                        $msg = 'Fatal error: '.$t->getMessage().' on '.$t->getFile().' on line '.$t->getLine(). ' ||| '.$this->request->server['REQUEST_URI'].$query_string.' post_data:'.$post;
                    }else {
                        $msg = 'Fatal error: '.$t->getMessage().' on '.$t->getFile().' on line '.$t->getLine(). ' ||| '.$this->request->server['REQUEST_URI'].$query_string;
                    }
                    // 记录错误异常
                    $exceptionClass = Application::getApp()->getExceptionClass();
                    $exceptionClass::shutHalt($msg);
				    $this->response->end(json_encode([
                        'ret' => 500,
                        'msg' => $msg,
                        'data' => ''
                    ]));
		        }
			}else {
				return $this->response->end(json_encode([
					'ret'=>500,
					'msg'=>'class method '.$action.' is static or private, protected property, can not be object call!',
					'data'=>''
				]));
			}
		}else {
			$this->response->status(404);
			$this->response->header('Content-Type','application/json; charset=UTF-8');
            return $this->response->end(json_encode([
                'ret'=> 404,
                'msg'=> 'Class file for '.$filePath.' is exit, but has undefined '.'"'.$action.'()'.'"'.' method',
                'data'=>''
            ]));
		}
	}

	/**
	 * redirectNotFound 重定向至NotFound类
	 * @return   array
	 */
	public function redirectNotFound($call_func = null) {
		if(isset($this->config['not_found_handle'])) {
			// 重定向至NotFound类
			list($namespace, $action) = $this->config['not_found_handle'];
            $controller = @array_pop(explode('\\', $namespace));
            // 重新设置一个NotFound类的route
            $this->request->server['ROUTE'] = '/'.$controller.'/'.$action;

            return trim(str_replace('/', '\\', $namespace), '/');
		}
	}

	/**
	 * isExistRouteFile 判断是否存在请求的route文件
	 * @param    string  $route  请求的路由uri
	 * @return   boolean
	 */
	public static function isExistRouteFile($route) {
		return isset(self::$routeCacheFileMap[$route]) ? self::$routeCacheFileMap[$route] : false;
	}

	/**
	 * setRouteFileMap 缓存路由的映射
	 * @param   string  $route  请求的路由uri
	 * @return  void
	 */
	public static function setRouteFileMap($route) {
		self::$routeCacheFileMap[$route] = true;
	}

	/**
	 * resetRouteDispatch 重置路由调度,将实际的路由改变请求,主要用在boostrap()中
	 * @param   string  $route  请求的路由uri
	 * @return  void
	 */
	public static function resetRouteDispatch($route) {
		if(strpos($route, '/') != 0) {
			Application::getApp()->request->server['PATH_INFO'] = '/'.$route;
		}
		Application::getApp()->request->server['PATH_INFO'] = $route;	
	}

}