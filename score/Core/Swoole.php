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

class Swoole extends Object {

	/**
	 * $config 当前应用层的配置 
	 * @var null
	 */
	public $config = null;

	/**
	 * $fd fd连接句柄标志
	 * @var null
	 */
	public $fd = null;

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
		// Component组件创建
		self::creatObject();
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
	protected function _init($recv = null) {
		static::init($recv);	
	}

	/**
	 * boostrap 初始化引导
	 */
	protected function _bootstrap($recv = null) {
		static::bootstrap($recv);
		Swfy::$config['application_service']::bootstrap($recv);
	}

	/**
	 * call 调用创建处理实例
	 * @return void
	 */
	public function run($fd, $recv) {
		Application::$app = $this;
		$this->fd = $fd;
		// 初始化处理
		$this->_init($recv);
		// 引导程序与环境变量的设置
		$this->_bootstrap($recv);
	}

	/**
     * getCurrentWorkerId 获取当前执行进程的id
     * @return int
     */
    public static function getCurrentWorkerId() {
        return Swfy::getServer()->worker_id;
    }

    /**
     * isWorkerProcess 判断当前进程是否是worker进程
     * @return boolean
     */
    public static function isWorkerProcess() {
        return Swfy::isWorkerProcess();
    }

    /**
     * isTaskProcess 判断当前进程是否是异步task进程
     * @return boolean
     */
    public static function isTaskProcess() {
        return (!self::isWorkerProcess()) ? true : false;
    }

 	/**
	 * afterRequest 请求结束后注册钩子执行操作
	 * @param	mixed   $callback 
	 * @param	boolean $prepend
	 * @return	void
	 */
	public function afterRequest(callable $callback, $prepend = false) {
		if(is_callable($callback)) {
			Hook::addHook(Hook::HOOK_AFTER_REQUEST, $callback, $prepend);
		}else {
			throw new \Exception(__NAMESPACE__.'::'.__function__.' the first param of type is callable');
		}
		
	}

	/**
	 * end
	 * @return  
	 */
	public function end() {
		// call hook callable
		Hook::callHook(Hook::HOOK_AFTER_REQUEST);
		if(!empty(ZModel::$_model_instances)) {
			ZModel::$_model_instances = [];
		}
		self::clearComponent(self::$_destroy_components);
		Application::$app = null;
	}

 	use \Swoolefy\Core\ComponentTrait,\Swoolefy\Core\ServiceTrait;
}