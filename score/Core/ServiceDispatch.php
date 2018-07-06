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
use Swoolefy\Core\AppDispatch;
use Swoolefy\Core\Application;

class ServiceDispatch extends AppDispatch {
	/**
	 * $callable 远程调用函数对象类
	 * @var array
	 */
	public $callable = [];

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
	public function __construct($callable, $params, $rpc_pack_header = []) {
		// 执行父类
		parent::__construct();
		$this->callable = $callable;
		$this->params = $params;
		Application::getApp()->mixed_params = $params;
		Application::getApp()->rpc_pack_header = $rpc_pack_header;
	}

	/**
	 * dispatch 路由调度
	 * @return void
	 */
	public function dispatch() {
		list($class, $action) = $this->callable;
		$class = trim($class, '/');
		if(!self::$routeCacheFileMap[$class]) {
			if(!$this->checkClass($class)){
				// TODO
				throw new \Exception("when dispatch, $class file is not exist", 1);
			}
		}
		
		$class = str_replace('/','\\', $class);
		$serviceInstance = new $class();
		$serviceInstance->mixed_params = $this->params;
		try{
			$serviceInstance->$action($this->params);
		}catch(\Exception $e) {
			throw new \Exception($e->getMessage());
		}catch(\Exception $e) {
			throw new \Exception("when dispatch, create $class Instance Fatal error, $class is not exist or $action is not exist!", 1);
		}
		
	}

	/**
	 * checkClass 检查请求实例文件是否存在
	 * @param  string  $class
	 * @return boolean
	 */
	public function checkClass($class) {
		$path = str_replace('\\', '/', $class);
		$path = trim($path, '/');
		$file = ROOT_PATH.DIRECTORY_SEPARATOR.$path.'.php';
		if(is_file($file)) {
			self::$routeCacheFileMap[$class] = true;
			return true;
		}
		return false;
	}

}