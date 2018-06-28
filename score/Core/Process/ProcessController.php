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

namespace Swoolefy\Core\Process;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\BaseObject;
use Swoolefy\Core\Hook;
use Swoolefy\Core\Application;

class ProcessController extends BaseObject {
	/**
	 * $config 应用层配置
	 * @var null
	 */
	public $config = null;

	/**
	 * $selfModel 控制器对应的自身model
	 * @var array
	 */
	public static $selfModel = [];

	/**
	 * __construct 初始化函数
	 */
	public function __construct() {
		// 应用层配置
		$this->config = Swfy::$appConfig;
	}

	/**
	 * beforeAction 在处理实际action之前执行
	 * @return   mixed
	 */
	public function _beforeAction() {
		return true;
	}

	/**
	 * afterAction 在返回数据之前执行
	 * @return   mixed
	 */
	public function _afterAction() {
		return true;
	}

	/**
	 * __destruct 返回数据之前执行,重新初始化一些静态变量
	 */
	public function __destruct() {
		// call hook callable
		Hook::callHook(Hook::HOOK_AFTER_REQUEST);

		if(method_exists($this,'_afterAction')) {
			static::_afterAction();
		}
		// 初始化销毁所有得单例model实例
		static::$selfModel = [];
		// 销毁某些组件
		self::clearComponent(self::$_destroy_components);

	}

	use \Swoolefy\Core\ComponentTrait,\Swoolefy\Core\ServiceTrait;
	
}