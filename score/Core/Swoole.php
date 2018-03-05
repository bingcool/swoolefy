<?php 
namespace Swoolefy\Core;

use Swoolefy\Core\Swfy;

class Swoole extends Object {

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
		// Component组件创建
		self::creatObject();
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
		// 初始化处理
		Init::_init();
		
	} 

	/**
	 * boostrap 初始化引导
	 */
	protected function bootstrap() {
		Swfy::$config['application_index']::bootstrap();	
	}


	/**
	 * call 调用创建处理实例
	 * @return [type] [description]
	 */
	public function call($request) {
		// 初始化处理
		self::init();
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
	 * end
	 * @return  
	 */
	public function end() {
		$this->callHook(self::HOOK_AFTER_REQUEST);
		// Model的实例化对象初始化为[]
		if(!empty(ZModel::$_model_instances)) {
			ZModel::$_model_instances = [];
		}
		// 初始化静态变量
		MTime::clear();
		// 清空某些组件,每次请求重新创建
		self::clearComponent(['mongodb']);

	}

 	use \Swoolefy\Core\ComponentTrait;
}