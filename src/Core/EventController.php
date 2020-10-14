<?php
/**
+----------------------------------------------------------------------
| swoolefy framework bases on swoole extension development, we can use it easily!
+----------------------------------------------------------------------
| Licensed ( https://opensource.org/licenses/MIT )
+----------------------------------------------------------------------
| @see https://github.com/bingcool/swoolefy
+----------------------------------------------------------------------
*/

namespace Swoolefy\Core;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\ZModel;
use Swoolefy\Core\BaseObject;
use Swoolefy\Core\Application;
use Swoolefy\Core\Log\LogManager;
use Swoolefy\Core\Coroutine\CoroutineManager;

class EventController extends BaseObject {

    use \Swoolefy\Core\ComponentTrait,\Swoolefy\Core\ServiceTrait;

    /**
	 * $app_conf 应用层配置
	 * @var array
	 */
	public $app_conf = null;

	/**
	 * $event_hooks 钩子事件
	 * @var array
	 */
	public $event_hooks = [];
	const HOOK_AFTER_REQUEST = 1;

    /**
     * @var array
     */
    protected $logs = [];

    /**
     * $is_end
     * @var boolean
     */
    protected $is_end = false;

    /**
     * $is_defer
     * @var boolean
     */
    protected $is_defer = false;

	/**
	 * __construct 初始化函数
     * @throws \Exception
	 */
	public function __construct(...$args) {
		$this->creatObject();
		$this->app_conf = Swfy::getAppConf();
		$this->coroutine_id = CoroutineManager::getInstance()->getCoroutineId();
		if($this->canCreateApp($this->coroutine_id)) {
			Application::setApp($this);
            $this->defer();
		}
	}

    /**
     * setApp  重置APP对象
     * @param int $coroutine_id
     * @return boolean
     * @throws \Exception
     */
	public function setApp($coroutine_id = null) {
		if($coroutine_id) {
			Application::removeApp($this->coroutine_id);
			$this->coroutine_id = $coroutine_id;
			Application::setApp($this);
			return  true;
		}
		return false;
	}

    /**
     * @param int $coroutine_id
     * @return null|string
     */
	public function setCid($coroutine_id = null) {
	    if(empty($coroutine_id)) {
            $coroutine_id = CoroutineManager::getInstance()->getCoroutineId();
        }
        $this->coroutine_id = $coroutine_id;
	    return $this->coroutine_id;
    }

    /**
     * getCid
     * @return mixed
     */
    public function getCid() {
        return $this->coroutine_id;
    }

    /**
     * canCreateApp
     * @param int $coroutine_id
     * @return boolean
     * @throws \Exception
     */
	public function canCreateApp($coroutine_id = null) {
		if(empty($coroutine_id)) {
            $coroutine_id = CoroutineManager::getInstance()->getCoroutineId();
        }
		$exists = Application::issetApp($coroutine_id);
		if($exists) {
			throw new \Exception("You had created EventApp Instance, yon can only registerApp once, so you can't create same coroutine");
		}
		return true;
	}

    /**
     * afterRequest
     * @param callable $callback
     * @param boolean $prepend
     * @return mixed
     * @throws \Exception
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
            }else {
                if(!isset($this->event_hooks[self::HOOK_AFTER_REQUEST][$key])) {
                    $this->event_hooks[self::HOOK_AFTER_REQUEST][$key] = $callback;
                }
            }
            return true;
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
     *pushComponentPools
     * @return boolean
     */
    public function pushComponentPools() {
        if(empty($this->component_pools) || empty($this->component_pools_obj_ids)) {
            return false;
        }

        foreach($this->component_pools as $name) {
            if(isset($this->container[$name])) {
                $obj = $this->container[$name];
                if(is_object($obj)) {
                    $obj_id = spl_object_id($obj);
                    if(in_array($obj_id, $this->component_pools_obj_ids)) {
                        \Swoolefy\Core\Coroutine\CoroutinePools::getInstance()->getPool($name)->pushObj($obj);
                    }
                }
            }
        }
    }

	/**
	 * beforeAction 在处理实际action之前执行
     * EventController不会执行该动作,所以继承于EventController其他类不要调用该method
	 * @return boolean
	 */
	public function _beforeAction() {
		return true;
	}

	/**
	 * afterAction 在返回数据之前执行
	 * @return boolean
	 */
	public function _afterAction() {
		return true;
	}

	/**
	 * setEnd
     * @return void
	 */
	public function setEnd() {
		$this->is_end = true;
	}

	/**
	 * end 重新初始化一些静态变量
	 */
	public function end() {
		if($this->is_end) {
			return true;
		}
		// set End
		$this->setEnd();
		// call hook callable
		static::_afterAction();
        // callHooks
        $this->callAfterEventHook();
        // handle log
        $this->handleLog();
        // remove Model
		ZModel::removeInstance();
		// push obj pools
		$this->pushComponentPools();
		// remove App Instance
		Application::removeApp($this->coroutine_id);
	}

    /**
     * defer
     * @return void
     */
    protected function defer() {
        if(\Swoole\Coroutine::getCid() > 0) {
            $this->is_defer = true;
            defer(function() {
                $this->end();
            });
        }
    }

    /**
     * @return boolean
     */
    public function isDefer() {
        return $this->is_defer;
    }
}