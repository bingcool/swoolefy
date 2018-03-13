<?php
namespace Swoolefy\Rpc;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\AppDispatch;

class RpcDispatch extends AppDispatch {
	/**
	 * $callable 远程调用函数对象类
	 * @var null
	 */
	public $callable = null;

	/**
	 * $params 远程调用参数
	 * @var null
	 */
	public $params = null;

	/**
	 * $deny_actions 禁止外部直接访问的action
	 * @var array
	 */
	public static $deny_actions = ['__construct','_beforeAction','_afterAction','__destruct'];

	/**
	 * __construct 
	 */
	public function __construct($callable, $params) {
		// 执行父类
		parent::__construct();
		$this->callable = $callable;
		$this->params = $params;
	}

	/**
	 * dispatch 路由调度
	 * @return void
	 */
	public function dispatch() {
		list($class, $action) = $this->callable;
		$class = trim($class, '/');
		if(!self::$routeCacheFileMap[$class]) {
			// 类文件不存在
			if(!$this->checkClass($class)){
				
			}
		}
		$class = str_replace('/','\\', $class);
		$serviceInstance = new $class();
		try{
			call_user_func_array([$serviceInstance, $action], [$this->params]);
		}catch(\Exception $e) {

		}
		
	}

	/**
	 * checkClass 检查请求实例文件是否存在
	 * @param  string  $class
	 * @return boolean
	 */
	public function checkClass($class) {
		$class = trim($class, '/');
		$file = APP_PATH.DIRECTORY_SEPARATOR.$class.DIRECTORY_SEPARATOR.'.php';
		if(is_file($file)) {
			self::$routeCacheFileMap[$class] = true;
			return true;
		}
		return false;
	}

}