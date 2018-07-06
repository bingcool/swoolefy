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
use Swoolefy\Core\ZModel;
use Swoolefy\Core\AppInit;
use Swoolefy\Core\HttpRoute;
use Swoolefy\Core\BaseServer;
use Swoolefy\Core\Application;
use Swoolefy\Core\Coroutine\CoroutineManager;

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
	 * $coroutine_id 
	 * @var [type]
	 */
	public $coroutine_id;

 	/**
 	 * $ExceptionHanderClass 异常处理类
 	 * @var string
 	 */
 	private $ExceptionHanderClass = 'Swoolefy\\Core\\SwoolefyException';

	/**
	 * __construct
	 * @param $config 应用层配置
	 */
	public function __construct(array $config=[]) {
		// 将应用层配置保存在上下文的服务
		$this->config = Swfy::$appConfig = $config;
		// 注册错误处理事件
		$protocol_config = Swfy::getConf();
		if(isset($protocol_config['exception_hander_class']) && !empty($protocol_config['exception_hander_class'])) {
			$this->ExceptionHanderClass = $protocol_config['exception_hander_class'];
		}
		register_shutdown_function($this->ExceptionHanderClass.'::fatalError');
      	set_error_handler($this->ExceptionHanderClass.'::appError');
	}

	/**
	 * init 初始化函数
	 * @return void
	 */
	protected function init() {
		// 初始化对象
		AppInit::_init();
		// session start,在一些微服务的http请求中无需session
		if(isset($this->config['session_start']) && $this->config['session_start']) {
			if(is_object($this->session)) {
				$this->session->start();
			};
		}
	} 

	/**
	 * boostrap 初始化引导
	 */
	protected function bootstrap() {
		Swfy::$config['application_index']::bootstrap($this->getRequestParam());	
	}

	/**
	 * run 执行
	 * @param  $request
	 * @param  $response
	 * @return void
	 */
	public function run($request, $response, $extend_data = null) {
		// Component组件创建
		parent::creatObject();
		// 赋值对象
		$this->request = $request;
		$this->response = $response;
		$coroutine_id = CoroutineManager::getInstance()->getCoroutineId();
		$this->coroutine_id = $coroutine_id;
		Application::setApp($this);
		// 初始化
		$this->init();
		// 引导程序与环境变量的设置
		$this->bootstrap();
		// 判断是否是在维护模式
		if(!$this->catch()) {
			// 调试模式，将打印出一些信息
			$this->debug();
			// 路由调度执行
			$route = new HttpRoute($extend_data);
			$route->dispatch();
		}
		// 销毁静态变量
		$this->clearStaticVar();
		// 请求结束
		return $this->end();
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
	public function afterRequest(callable $callback, $prepend = true) {
		if(is_callable($callback)) {
			Hook::addHook(Hook::HOOK_AFTER_REQUEST, $callback, $prepend);
		}else {
			throw new \Exception(__NAMESPACE__.'::'.__function__.' the first param of type is callable');
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
	 *clearStaticVar 销毁静态变量
	 * @return void
	 */
	public function clearStaticVar() {
		// call hook callable
		Hook::callHook(Hook::HOOK_AFTER_REQUEST);
		ZModel::removeInstance();
	}

	/**
	 * end 请求结束
	 * @return
	 */
	public function end() {
		// 销毁当前的请求应用对象
		Application::removeApp();
		// 设置一个异常结束
		@$this->response->end();
	}

	//使用trait的复用特性
	use \Swoolefy\Core\AppTrait,\Swoolefy\Core\ServiceTrait;
}