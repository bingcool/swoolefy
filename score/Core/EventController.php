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
use Swoolefy\Core\BaseObject;
use Swoolefy\Core\Application;
use Swoolefy\Core\Coroutine\CoroutineManager;

class EventController extends BaseObject {
	/**
	 * $config 应用层配置
	 * @var null
	 */
	public $config = null;

	/**
	 * $selfModel 控制器对应的自身model
	 * @var array
	 */
	public $selfModel = [];

	/**
	 * $event_hooks 钩子事件
	 * @var array
	 */
	public $event_hooks = [];
	const HOOK_AFTER_REQUEST = 1;

	/**
	 * __construct 初始化函数
	 */
	public function __construct() {
		// 应用层配置
		$this->config = Swfy::$appConfig;
		$coroutine_id = CoroutineManager::getInstance()->getCoroutineId();
		$this->coroutine_id = $coroutine_id;
		Application::setApp($this);
	}

	/**
	 * setApp 重置APP对象
	 * @param  string  $coroutine_id
	 */
	public function setApp($coroutine_id = null) {
		if($coroutine_id) {
			Application::removeApp();
			$this->coroutine_id = $coroutine_id;
			Application::setApp($this);
			return  true;
		}

		return false;
	}

	/**
	 * getCid 
	 * @return  mixed
	 */
	public function getCid() {
		return $this->coroutine_id;
	}

	/**
	 * afterRequest 
	 * @param  callable $callback
	 * @param  boolean  $prepend
	 * @return mixed
	 */
	public function afterRequest(callable $callback, $prepend = false) {
		if(is_callable($callback, true, $callable_name)) {
			$key = md5($callable_name);
			if($prepend) {
				if(!isset($this->event_hooks[self::HOOK_AFTER_REQUEST])) {
					$this->event_hooks[self::HOOK_AFTER_REQUEST] = [];
				}
				if(!isset($this->event_hooks[self::HOOK_AFTER_REQUEST][$key])) {
					$this->event_hooks[self::HOOK_AFTER_REQUEST][$key] = array_merge([$key=>$callback], $this->event_hooks[self::HOOK_AFTER_REQUEST]);
				}
				return true;
			}else {
				// 防止重复设置
				if(!isset($this->event_hooks[self::HOOK_AFTER_REQUEST][$key])) {
					$this->event_hooks[self::HOOK_AFTER_REQUEST][$key] = $callback;
				}
				return true;
			}
		}else {
			throw new \Exception(__NAMESPACE__.'::'.__function__.' the first param of type is callable');
		}
		
	}

	/**
	 * callEventHook 
	 * @return void
	 */
	public function callAfterEventHook() {
		if(isset($this->event_hooks[self::HOOK_AFTER_REQUEST]) && !empty($this->event_hooks[self::HOOK_AFTER_REQUEST])) {
			foreach($this->event_hooks[self::HOOK_AFTER_REQUEST] as $func) {
				$func();
			}
		}
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
		// callhooks
		if(method_exists($this, 'callAfterEventHook')) {
			$this->callAfterEventHook();
		};
		// call hook callable
		if(method_exists($this, '_afterAction')) {
			static::_afterAction();
		}
		ZModel::removeInstance();
		Application::removeApp();
	}

	use \Swoolefy\Core\ComponentTrait,\Swoolefy\Core\ServiceTrait;
}