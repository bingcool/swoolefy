<?php
namespace Swoolefy\Core;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\Application;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Swoolefy\Tool\ArrayHelper\ArrayForHelp;
use Swoolefy\Core\HttpRoute;

class App extends \Swoolefy\Core\Component {
	/**
	 * $request 当前请求的对象
	 * @var null
	 */
	public $request = null;
	
	/**
	 * $response 当前请求的响应对象
	 * @var null
	 */
	public $response = null;

	/**
	 * $config 当前应用层的配置 
	 * @var null
	 */
	public $config = null;

	/**
	 * $hooks 保存钩子执行函数
	 * @var array
	 */
	public $hooks = [];
 	const HOOK_AFTER_REQUEST = 1;

	/**
	 * __construct
	 * @param $config 应用层配置
	 */
	public function __construct(array $config=[]) {
		// 将应用层配置保存在上下文的服务
		$this->config = Swfy::$appConfig = $config;
		parent::creatObject();
		// 注册错误处理事件
		register_shutdown_function('Swoolefy\Core\SwoolefyException::fatalError');
		// 由于swoole不支持set_exception_handler()
      	// set_exception_handler('Swoolefy\Core\SwoolefyException::appException');
      	set_error_handler('Swoolefy\Core\SwoolefyException::appError');
	}

	/**
	 * init 初始化函数
	 * @return void
	 */
	protected function init() {
		// 初始化超全局变量数组和对象
		Init::_init();
		// session start
		$this->session->start();
	}

	/**
	 * boostrap 初始化引导
	 */
	protected function bootstrap() {
		Swfy::$config['application_index']::bootstrap();	
	}

	/**
	 * run 执行
	 * @param  $request
	 * @param  $response
	 * @return void
	 */
	public function run($request, $response) {
		// 赋值对象
		$this->request = $request;
		$this->response = $response;
		Application::$app = $this;
		// 初始化
		$this->init();
		// 引导程序与环境变量的设置
		$this->bootstrap();
		// 判断是否是在维护模式
		if(!$this->catch()) {
			// 调试模式，将打印出一些信息
			$this->debug();
			// 路由调度执行
			$route = new HttpRoute();
			$route->dispatch();
		}

		// 请求结束
		$this->end();
		
		return true;
	}

	/**
	 * catch 捕捉拦截所有请求，进入维护模式
	 * @return void
	 */
	public function catch() {
		// 获取配置信息
		if(isset($this->config['catch_all_info']) && $info = $this->config['catch_all_info']) {
			if(is_array($info)) {
				$this->response->header('Content-Type','application/json; charset=UTF-8');
				return $this->response->end(json_encode($info));
			}else {
				$this->response->header('Content-Type','text/html; charset=UTF-8');
				$this->response->end($info);
			}
			
			return true;
		}
		return false;
	}

	/**
	 * afterRequest 请求结束后注册钩子执行操作
	 * @param	mixed   $callback 
	 * @param	boolean $prepend
	 * @return	void
	 */
	public function afterRequest(callable $callback, $prepend=false) {
		if(is_callable($callback)) {
			$this->addHook(self::HOOK_AFTER_REQUEST, $callback, $prepend);
		}else {
			throw new \Exception(__NAMESPACE__.'::'.__function__.' the first param of type is callable');
		}
		
	}

	/**
	 * addHook 添加钩子函数
	 * @param    int   $type
	 * @param 	 mixed $func
	 * @param    boolean $prepend
	 * @return     void
	 */
	protected function addHook($type, $func, $prepend=false) {
		if($prepend) {
			array_unshift($this->hooks[$type], $func);
		}else {
			$this->hooks[$type][] = $func;
		}
	}

	/**
	 * callhook 调用钩子函数
	 * @param [type] $type
	 * @return  void
	 */
	protected function callHook($type) {
		if(isset($this->hooks[$type])) {
			foreach($this->hooks[$type] as $func) {
				$func();
			}
		}
	}

	/**
	 * debug 调试函数
	 * @return 
	 */
	protected function debug() {
		if(SW_DEBUG) {
			$dumpInfo = \Swoolefy\Core\Application::$dump;
			if(!is_null($dumpInfo)) {
				$this->response->header('Content-Type','text/html; charset=UTF-8');
				$this->response->write($dumpInfo);
			}
		}
	}

	/**
	 * end 请求结束
	 * @return  
	 */
	public function end() {
		$this->callHook(self::HOOK_AFTER_REQUEST);
		// Model的实例化对象初始化为[]
		if(!empty(ZModel::$_model_instances)) {
			ZModel::$_model_instances = [];
		}
		// 销毁应用对象
		Application::$app = null;
		// 初始化静态变量
		MTime::clear();
		// 清空某些组件,每次请求重新创建
		self::clearComponent(['mongodb','session']);
	}

	//使用trait的复用特性
	use \Swoolefy\Core\AppTrait,\Swoolefy\Core\ServiceTrait;
}