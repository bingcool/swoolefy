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
	 * $route
	 * @var null
	 */
	public  $route = null;

	/**
	 * __construct
	 * @param $config 应用层配置
	 */
	public function __construct(array $config=[]) {
		$this->config = $config;
		parent::creatObject();
		// 注册错误处理事件
		register_shutdown_function('Swoolefy\Core\SwoolefyException::fatalError');
      	set_exception_handler('Swoolefy\Core\SwoolefyException::appException');
	}

	/**
	 * init 初始化函数
	 * @return void
	 */
	public function init() {
		// 初始化启动session
		// if(!isset($this->config['session_start']) || (isset($this->config['session_start']) && $this->config['session_start'] === true)) {
		// 	if(!isset($_SESSION)) {
		// 		session_start();
		// 	}
		// }
	}

	/**
	 * cors 
	 * @return  
	 */
	public function cors() {
		if(isset($this->config['cors']) && is_array($this->config['cors'])) {
			$cors = $this->config['cors'];
			foreach($cors as $k=>$value) {
				if(is_array($value)) {
					$this->response->header($k,implode(',',$value));
				}else {
					$this->response->header($k,$value);
				}
			}
		}
	}

	/**
	 * run
	 * @param  $request
	 * @param  $response
	 * @return void
	 */
	public function run($request, $response) {
		// 赋值对象
		$this->request = $request;
		$this->response = $response;
		$this->init();
		// 判断是否是在维护模式
		// ob_start();
		if(!$this->catch()) {
			// 执行应用
			Application::$app = $this;
			$route = new HttpRoute();
			$route->dispatch();
		}
		// ob_end_clean();
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
				$this->response->gzip(1);
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
		if(@isset(ZModel::$_model_instances)) {
			ZModel::$_model_instances = [];
		}
	}

	//使用trait的复用特性
	use \Swoolefy\Core\AppTrait;
}