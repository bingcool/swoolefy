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
use Swoolefy\Core\Log\LogManager;
use Swoolefy\Core\Controller\BController;
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
	 * $app_conf 当前应用层的配置
	 * @var null
	 */
	public $app_conf = null;

	/**
	 * $coroutine_id 
	 * @var null
	 */
	public $coroutine_id;

    /**
     * $controllerInstance 控制器实例
     * @var null
     */
    protected $controllerInstance = null;

    /**
     * $log 日志
     */
    protected $logs = [];

    /**
     * @var bool
     */
    protected $is_end = false;

    /**
     * $is_defer
     * @var boolean
     */
    protected $is_defer = false;

	/**
	 * __construct
	 * @param  array $config 应用层配置
	 */
	public function __construct(array $conf = []) {
		$this->app_conf = $conf;
	}

	/**
	 * init 初始化函数
	 * @return void
	 */
	protected function _init($request) {
		AppInit::init($request);
		if(isset($this->app_conf['session_start']) && $this->app_conf['session_start']) {
			if(is_object($this->get('session'))) {
				$this->get('session')->start();
			};
		}
	} 

	/**
	 * boostrap 初始化引导
	 */
	protected function _bootstrap($request) {
        $conf = BaseServer::getConf();
	    if(isset($conf['application_index'])) {
	    	$application_index = $conf['application_index'];
	    	if(class_exists($application_index)) {
            	$conf['application_index']::bootstrap($this->getRequestParams());
        	}
        }
	}

	/**
	 * run 执行
	 * @param  $request
	 * @param  $response
     * @throws \Throwable
	 * @return boolean
	 */
	public function run($request, $response, $extend_data = null) {
	    try {
            parent::creatObject();
            $this->request = $request;
            $this->response = $response;
            $this->coroutine_id = CoroutineManager::getInstance()->getCoroutineId();
            Application::setApp($this);
            $this->_init($request);
            $this->_bootstrap($request);
            $this->defer();
            if(!$this->catchAll()) {
                $route = new HttpRoute($extend_data);
                $route->dispatch();
            }
        }catch (\Throwable $throwable) {
            throw $throwable;
        }finally {
        	if(!$this->is_defer) {
        		$this->clearStaticVar();
	            $this->end();
        	}
            return true;
        }
	}

	/**
	 * setAppConf
	 */
	public function setAppConf(array $conf = []) {
		static $is_reset_app_conf;
		if(!isset($is_reset_app_conf)) {
			if(!empty($conf)) {
				$this->app_conf = $conf;
				Swfy::setAppConf($conf);
				BaseServer::setAppConf($conf);
				$is_reset_app_conf = true;
			}
		}
	}

    /**
     * @param BController $controller
     */
	public function setControllerInstance(BController $controller) {
	    $this->controllerInstance = $controller;
    }

    /**
     * @return |null
     */
    public function getControllerInstance() {
        return $this->controllerInstance;
    }

    /**
	 * catchAll 捕捉拦截所有请求，进入维护模式
	 * @return boolean
	 */
	public function catchAll() {
	    // catchAll
		if(isset($this->app_conf['catch_handle']) && $handle = $this->app_conf['catch_handle']) {
            $this->is_end = true;
			if(is_array($handle)) {
				$this->response->header('Content-Type','application/json; charset=UTF-8');
				$this->response->end(json_encode($handle, JSON_UNESCAPED_UNICODE));
			}else if($handle instanceof \Closure) {
				call_user_func($handle, $this->request, $this->response);
			}else {
                $this->response->header('Content-Type','text/html; charset=UTF-8');
                $this->response->end($handle);
            }
			return true;
		}
		return false;
	}

	/**
	 * afterRequest 请求结束后注册钩子执行操作
	 * @param	mixed   $callback 
	 * @param	boolean $prepend
     * @throws  \Exception
	 * @return	void
	 */
	public function afterRequest(callable $callback, bool $prepend = false) {
		if(is_callable($callback)) {
			Hook::addHook(Hook::HOOK_AFTER_REQUEST, $callback, $prepend);
		}else {
			throw new \Exception(__NAMESPACE__.'::'.__function__.' the first param of type is callable');
		}
	}

    /**
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
     * @param null $cid
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
     * 获取配置的异常处理类
     */
	public function getExceptionClass() {
        return BaseServer::getExceptionClass();
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
    	if(!empty($this->component_pools) && !empty($this->component_pools_obj_ids)) {
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
    }

    /**
     * setEnd
     */
    public function setEnd() {
        $this->is_end = true;
    }

	/**
	 * end 请求结束
	 * @return void
	 */
	public function end() {
		// log hander
        $this->handerLog();
        // push obj pools
        $this->pushComponentPools();
        // remove App Instance
		Application::removeApp();
        if(!$this->is_end) {
            @$this->response->end();
        }
	}

	/**
	 * defer 
	 * @return void
	 */
	public function defer() {
		if(\Co::getCid() > 0) {
			$this->is_defer = true;
			defer(function() {
			    $this->clearStaticVar();
	            $this->end();
        	});
		}
	}

	use \Swoolefy\Core\AppTrait,\Swoolefy\Core\ServiceTrait;
}