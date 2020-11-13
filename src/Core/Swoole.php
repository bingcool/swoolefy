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

use Swoolefy\Core\Coroutine\CoroutineManager;

class Swoole extends BaseObject {

    use \Swoolefy\Core\ComponentTrait,\Swoolefy\Core\ServiceTrait;

    /**
	 * $app_conf 当前应用层的配置
	 * @var array
	 */
	public $app_conf = null;

	/**
	 * $fd fd连接句柄标志
	 * @var null
	 */
	public $fd = null;

	/**
	 * $mixed_params rpc,udp,websocket传递的参数寄存属性
     * @var null
	 */
	protected $mixed_params;

	/**
	 * $rpc_pack_header rpc的包头数据
	 * @var array
	 */
	protected $rpc_pack_header = [];

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
		$this->app_conf = $config;
	}

	/**
	 * init 初始化函数
	 * @return void
	 */
	protected function _init($recv = null) {
		static::init($recv);	
	}

	/**
	 * bootstrap 初始化引导
	 */
	protected function _bootstrap($recv = null) {
		static::bootstrap($recv);
		if(isset(Swfy::$conf['application_service'])) {
			$application_service = Swfy::$conf['application_service'];
			if(class_exists($application_service)) {
            	Swfy::$conf['application_service']::bootstrap($recv);
        	}
		}
	}

    /**
     * call 调用创建处理实例
     * @return void
     * @throws \Exception
     */
	public function run($fd, $recv) {
		$this->creatObject();
		$this->coroutine_id = CoroutineManager::getInstance()->getCoroutineId();
		Application::setApp($this);
		$this->fd = $fd;
        $this->defer();
		$this->_init($recv);
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
     * @throws \Exception
     */
    public static function isWorkerProcess() {
        return Swfy::isWorkerProcess();
    }

    /**
     * isTaskProcess 判断当前进程是否是异步task进程
     * @return boolean
     * @throws \Exception
     */
    public static function isTaskProcess() {
        return Swfy::isTaskProcess();
    }

    /**
     * @param int $coroutine_id
     * @return int
     */
    public function setCid($coroutine_id = null) {
        if(empty($coroutine_id)) {
            $coroutine_id = CoroutineManager::getInstance()->getCoroutineId();
        }
        $this->coroutine_id = $coroutine_id;
        return $this->coroutine_id;
    }

    /**
     * @param $mixed_params
     */
    public function setMixedParams($mixed_params) {
        $this->mixed_params = $mixed_params;
    }

    /**
     * @param array $rpc_pack_header
     */
    public function setRpcPackHeader(array $rpc_pack_header) {
        $this->rpc_pack_header = $rpc_pack_header;
    }

    /**
     * @return mixed
     */
    public function getMixedParams() {
        return $this->mixed_params;
    }

    /**
     * getCid
     * @return int
     */
    public function getCid() {
        return $this->coroutine_id;
    }

    /**
     * getRpcPackHeader  获取rpc的pack头信息,只适用于rpc服务
     * @return array
     * @throws \Exception
     */
	public function getRpcPackHeader() {
		if(!$this->isWorkerProcess()) {
            throw new \Exception(sprintf("%s::getRpcPackHeader() only can use in worker process", __CLASS__));
        }
        if(!BaseServer::isRpcApp()) {
            throw new \Exception(sprintf("%s::getRpcPackHeader() method only can be called by TCP or RPC server!, because only rpc have pack setting", __CLASS__));
        }
        return $this->rpc_pack_header;

    }

    /**
	 * getRpcPackBodyParams 获取rpc的包体数据
     * @throws \Exception
	 * @return mixed
	 */
	public function getRpcPackBodyParams() {
		if(!$this->isWorkerProcess()) {
            throw new \Exception(sprintf("%s::getRpcPackBodyParams() only can use in worker process",__CLASS__));
        }
        if(!BaseServer::isRpcApp()) {
            throw new \Exception(sprintf("%s::getRpcPackBodyParams() method only can be called by TCP or RPC server!, because only rpc have pack setting",__CLASS__));
        }

        return $this->mixed_params;
	}

	/**
	 * getUdpData 获取udp的数据
     * @throws \Exception
	 * @return mixed
	 */
	public function getUdpData() {
		if(!$this->isWorkerProcess()) {
            throw new \Exception(sprintf("%s::getUdpData() only can use in worker process",__CLASS__));
        }

        if(!BaseServer::isUdpApp()) {
            throw new \Exception(sprintf("%s::getUdpData() method only can be called by UDP server",__CLASS__));
        }

        return $this->mixed_params;
	}

	/**
	 * getWebsocketMsg 获取websocket的信息
     * @throws \Exception
	 * @return mixed
	 */
	public function getWebsocketMsg() {
		if(!$this->isWorkerProcess()) {
            throw new \Exception(sprintf("%s::getWebsocketMsg() only can use in worker process",__CLASS__));
        }

        if(!BaseServer::isWebsocketApp()) {
            throw new \Exception(sprintf("%s::getWebsocketMsg() method only can be called by WEBSOCKET server",__CLASS__));
        }

        return $this->mixed_params;
    }

	/**
	 * getFd worker进程中可以读取到值，task进程不能，默认返回null
	 * @return mixed
	 */
	public function getFd() {
        return $this->fd;
	}

    /**
     * @return string | SwoolefyException
     */
    public function getExceptionClass() {
        return BaseServer::getExceptionClass();
    }

 	/**
	 * afterRequest 请求结束后注册钩子执行操作
	 * @param	mixed   $callback 
	 * @param	boolean $prepend
	 * @return	bool
	 */
	public function afterRequest(callable $callback, $prepend = false) {
        return Hook::addHook(Hook::HOOK_AFTER_REQUEST, $callback, $prepend);
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
     * @return void
     */
	public function end() {
		// call hook callable
		Hook::callHook(Hook::HOOK_AFTER_REQUEST);
		// log handle
        $this->handleLog();
        // remove
		ZFactory::removeInstance();
		// push obj pools
		$this->pushComponentPools();
		// remove App Instance
		Application::removeApp();
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

}