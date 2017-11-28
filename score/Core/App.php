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
	 * __construct
	 * @param $config 应用层配置
	 */
	public function __construct(array $config=[]) {
		$this->config = $config;
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
		\Swoolefy\Core\Init::_init();
		// 初始化启动session
		// if(!isset($this->config['session_start']) || (isset($this->config['session_start']) && $this->config['session_start'] === true)) {
		// 	if(!isset($_SESSION)) {
		// 		session_start();
		// 	}
		// }
	}

	/**
	 * boostrap 初始化引导
	 */
	public function bootstrap() {
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
	 * end 请求结束
	 * @return  
	 */
	public function end() {
		// Model的实例化对象初始化为[]
		if(!empty(ZModel::$_model_instances)) {
			ZModel::$_model_instances = [];
		}
		// 销毁应用对象
		Application::$app = null;
		// 初始化静态变量
		MTime::clear();
	}

	//使用trait的复用特性
	use \Swoolefy\Core\AppTrait,\Swoolefy\Core\ServiceTrait;
}