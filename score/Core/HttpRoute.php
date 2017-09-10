<?php
namespace Swoolefy\Core;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\Application;
use Swoolefy\Core\Dispatch;

class HttpRoute extends Dispatch {
	/**
	 * $request请求对象
	 * @var null
	 */
	public $request = null;

	/**
	 * $response
	 * @var null
	 */
	public $response = null;

	/**
	 * $require_uri请求的url
	 * @var null
	 */
	public $require_uri = null;

	/**
	 * $config配置值
	 * @var null
	 */
	public $config = null;

	public $deny_actions = ['__construct','_beforeAction','_afterAction','__destruct'];
	/**
	 * __construct
	 */
	public function __construct() {
		// 执行父类
		parent::__construct();
		// 获取请求对象
		$this->request = Application::$app->request;
		$this->require_uri = $this->request->server['path_info'];

		$this->response = Application::$app->response;
		$this->config = Application::$app->config; 
	}

	/**
	 * dispatch
	 * @return [type] [description]
	 */
	public function dispatch() {
		
		// pathinfo的模式
		if($this->config['route_model'] == 1) {
			// 采用默认路由
			if($this->require_uri == '/' || $this->require_uri == '//') {
				$this->require_uri = '/'.$this->config['default_route'];
			}
			// 去掉开头的'/'
			$route_uri = substr($this->require_uri,1);
			if($route_uri) {
				// 分割出route
				$route_arr = explode('/',$route_uri);
				if(count($route_arr) == 1){
					$module = null;
					$controller = $route_arr[0];
					$action = 'index';
					$route_uri = $controller.'/'.'index';
				}elseif(count($route_arr) == 2) {
					$module = null;
					// Controller/Action模式
					$controller = $route_arr[0];
					$action = $route_arr[1];
				}elseif(count($route_arr) == 3) {
					// Model/Controller/Action模式
					$module = $route_arr[0];
					$controller = $route_arr[1];
					$action = $route_arr[2];
				}
			}
		}else if($this->config['route_model'] == 2) {
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
		$this->request->server['route'] = $this->require_uri;
		// 定义禁止直接外部访问的方法
		if(in_array($action, $this->deny_actions)) {
			$this->response->end($action.' method is not be exec!');
		}
		
		if($module) {
			$this->invoke($module,$controller,$action);
		}else {
			$this->invoke($module=null,$controller,$action);
		}

		// 设置一个异常结束
		@$this->response->end();
	}

	/**
	 * invoke
	 * @param  $module
	 * @param  $controller
	 * @param  $action
	 * @return boolean
	 */
	public function invoke($module=null,$controller=null,$action=null) {
		// 判断是否存在这个类文件
		if($module) {
			$filePath = APP_PATH.DIRECTORY_SEPARATOR.'Module'.DIRECTORY_SEPARATOR.$module.DIRECTORY_SEPARATOR.'Controller'.DIRECTORY_SEPARATOR.$controller.'.php';
			if(!is_file($filePath)) {
				$this->response->status(404);
				$this->response->header('Content-Type','text/html; charset=UTF-8');
				if(SW_DEBUG) {
					  $this->response->end($filePath.' is not exit!');
				}else {
					$tpl404 = file_get_contents(TEMPLATE_PATH.DIRECTORY_SEPARATOR.$this->config['not_found_template']);
					$this->response->end($tpl404);
				}
				return;
			}

			// 访问类的命名空间
			$class = $this->config['default_namespace'].'\\'.'Module'.'\\'.$module.'\\'.'Controller'.'\\'.$controller;

		}else {
			$filePath = APP_PATH.DIRECTORY_SEPARATOR.'Controller'.DIRECTORY_SEPARATOR.$controller.'.php';
			if(!is_file($filePath)) {
				$this->response->status(404);
				$this->response->header('Content-Type','text/html; charset=UTF-8');
				if(SW_DEBUG) {
					$this->response->end($filePath.' is not exit!');
					return;
				}else {
					$tpl404 = file_get_contents(TEMPLATE_PATH.DIRECTORY_SEPARATOR.$this->config['not_found_template']);
					$this->response->end($tpl404);
					return;
				}
			}

			// 访问类的命名空间
			$class = $this->config['default_namespace'].'\\'.'Controller'.'\\'.$controller;
		}
		// 创建控制器实例
		$controllerInstance = new $class();
		// 如果存在该类和对应的方法
		$reflector = new \ReflectionClass($controllerInstance);
		if($reflector->hasMethod($action)) {
			$method = new \ReflectionMethod($controllerInstance, $action);
			if($method->isPublic() && !$method->isStatic()) {
				try{
           	 		$method->invoke($controllerInstance);
		        }catch (\ReflectionException $e) {
		            // 方法调用发生异常后 引导到__call方法处理
		            $method = new \ReflectionMethod($controllerInstance,'__call');
		            $method->invokeArgs($controllerInstance,array($action,''));
		        }
			}else {
				if(SW_DEBUG) {
					$this->response->end('class method '.$action.' is static property,can not be object call!');
				}else {
					$tpl404 = file_get_contents(TEMPLATE_PATH.DIRECTORY_SEPARATOR.$this->config['not_found_template']);
					$this->response->end($tpl404);
				}
				return;
			}
		}else {
			$this->response->status(404);
			$this->response->header('Content-Type','text/html; charset=UTF-8');
			if(SW_DEBUG) {
				$this->response->end('Class file for '.$filePath.' is exit, but'.$controller.'.php'.' has not '.'"'.$action.' method!"');
			}else {
				$tpl404 = file_get_contents(TEMPLATE_PATH.DIRECTORY_SEPARATOR.$this->config['not_found_template']);
				$this->response->end($tpl404);
			}
			return;
		}
	}

}