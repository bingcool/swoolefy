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
use Swoolefy\Core\Log\LogManager;
use Swoolefy\Core\Coroutine\CoroutineManager;

class EventController extends BaseObject {
	/**
	 * $app_conf 应用层配置
	 * @var array
	 */
	public $app_conf = null;

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
	 * @param  int  $coroutine_id
     * @return boolean
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
     * @return  mixed
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
			throw new \Exception("You haved created EventApp Instance, yon can only registerApp once, so you can't ceate secornd in same coroutine");
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
     * @param $level
     * @param $log
     */
    public function setLog($level, $log) {
        if(!isset($this->logs[$level])) {
            $this->logs[$level] = [];
        }
        array_push($this->logs[$level], $log);
    }

    /**
     * @return array
     */
    public function getLog() {
        return $this->logs;
    }

    /**
     * handerLog
     */
    public function handerLog() {
        // log send
        if(!empty($logs = $this->getLog())) {
            foreach($logs as $action => $log) {
                if(!empty($log)) {
                    LogManager::getInstance()->{$action}($log);
                    $this->logs[$action] = [];
                }
            }
        }
    }

    /**
     *pushComponentPools
     * @return 
     */
    public function pushComponentPools() {
        if(!empty($this->component_pools) || !empty($this->component_pools_obj_ids)) {
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
	 * setEnd
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
        // callhooks
        $this->callAfterEventHook();
        // handle log
        $this->handerLog();
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
    public function defer() {
        if(\Co::getCid() > 0) {
            $this->is_defer = true;
            defer(function() {
                $this->end();
            });
        }
    }

    /**
     * @return bool
     */
    public function isDefer() {
        return $this->is_defer;
    }

	use \Swoolefy\Core\ComponentTrait,\Swoolefy\Core\ServiceTrait;
}