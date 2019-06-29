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
use Swoolefy\Core\BaseServer;
use Swoolefy\Core\Log\LogManager;
use Swoolefy\Core\Coroutine\CoroutineManager;

class Swoole extends BaseObject {

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
	 * $mixed_params rpc,udp,websocket传递的参数寄存属性
     * @var null
	 */
	public $mixed_params;

	/**
	 * $rpc_pack_header rpc的包头数据
	 * @var array
	 */
	public $rpc_pack_header = [];

	/**
	 * $coroutine_id 
	 * @var null
	 */
	public $coroutine_id;

    /**
     * $log 日志
     */
    protected $logs = [];

    /**
     * $is_defer
     * @var boolean
     */
    protected $is_defer = false;

    /**
	 * __construct
	 * @param array $config 应用层配置
	 */
	public function __construct(array $config = []) {
		$this->config = $config;
		Swfy::setAppConf($config);
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
		if(isset(Swfy::$config['application_service'])) {
			$application_service = Swfy::$config['application_service'];
			if(class_exists($application_service)) {
            	Swfy::$config['application_service']::bootstrap($recv);
        	}
		}
	}

	/**
	 * call 调用创建处理实例
	 * @return void
	 */
	public function run($fd, $recv) {
		$this->creatObject();
		$this->coroutine_id = CoroutineManager::getInstance()->getCoroutineId();
		Application::setApp($this);
		$this->fd = $fd;
		$this->_init($recv);
		$this->_bootstrap($recv);
		$this->defer();
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
        return Swfy::isTaskProcess();
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
	 * getRpcPackHeader  获取rpc的pack头信息,只适用于rpc服务
     * @throws  \Exception
	 * @return   array
	 */
	public function getRpcPackHeader() {
		if($this->isWorkerProcess()) {
			if(BaseServer::isRpcApp()) {
				return $this->rpc_pack_header;
			}else {
				throw new \Exception("getRpcPackHeader() method only can be called by TCP or RPC server!, because only rpc have pack setting!");
			}
		}else {
			throw new \Exception("getRpcPackHeader() only can use in worker process!", 1);
		}
	}

    /**
	 * getRpcPackBodyParams 获取rpc的包体数据
     * @throws \Exception
	 * @return mixed
	 */
	public function getRpcPackBodyParams() {
		if($this->isWorkerProcess()) {
			if(BaseServer::isRpcApp()) {
				return $this->mixed_params;
			}else {
				throw new \Exception("getRpcPackBodyParams() method only can be called by TCP or RPC server!, because only rpc have pack setting!");
			}
		}else {
			throw new \Exception("getRpcPackBodyParams() only can use in worker process!", 1);
		}
	}

	/**
	 * getUdpData 获取udp的数据
     * @throws \Exception
	 * @return mixed
	 */
	public function getUdpData() {
		if($this->isWorkerProcess()) {
			if(BaseServer::isUdpApp()) {
				return $this->mixed_params;
			}else {
				throw new \Exception("getUdpData() method only can be called by UDP server!");
			}
		}else {
			throw new \Exception("getUdpData() only can use in worker process!", 1);
		}
	}

	/**
	 * getWebsockMsg 获取websocket的信息
     * @throws \Exception
	 * @return mixed
	 */
	public function getWebsockMsg() {
		if($this->isWorkerProcess()) {
			if(BaseServer::isWebsocketApp()) {
				return $this->mixed_params;
			}else {
				throw new \Exception("getWebsockMsg() method only can be called by WEBSOCKET server!");
			}	
		}else {
			throw new \Exception("getWebsockMsg() only can use in worker process!", 1);
		}
	}

	/**
	 * getFd worker进程中可以读取到值，task进程不能，默认返回null
	 * @return  mixed
	 */
	public function getFd() {
        return $this->fd;
	}

    /**
     * 获取配置的异常处理类
     */
    public function getExceptionClass() {
        return BaseServer::getExceptionClass();
    }

 	/**
	 * afterRequest 请求结束后注册钩子执行操作
	 * @param	mixed   $callback 
	 * @param	boolean $prepend
     * @throws  \Exception
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
	 * end
	 */
	public function end() {
		// call hook callable
		Hook::callHook(Hook::HOOK_AFTER_REQUEST);
		// log hander
        $this->handerLog();
        // remove Model
		ZModel::removeInstance();
		// push obj pools
		$this->pushComponentPools();
		// remove App Instance
		Application::removeApp();
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

 	use \Swoolefy\Core\ComponentTrait,\Swoolefy\Core\ServiceTrait;
}