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
	 * $config 当前应用层的配置 
	 * @var null
	 */
	public $config = null;

	/**
	 * $coroutine_id 
	 * @var null
	 */
	public $coroutine_id;

    /**
     * $log 日志
     */
    protected $logs = [];

    protected $is_end = false;

	/**
	 * __construct
	 * @param  array $config 应用层配置
	 */
	public function __construct(array $config = []) {
		$this->config = $config;
		Swfy::setAppConf($config);
	}

	/**
	 * init 初始化函数
	 * @return void
	 */
	protected function _init() {
		AppInit::_init();
		// session start
		if(isset($this->config['session_start']) && $this->config['session_start']) {
			if(is_object($this->session)) {
				$this->session->start();
			};
		}
	} 

	/**
	 * boostrap 初始化引导
	 */
	protected function _bootstrap() {
	    if(isset(Swfy::$config['application_index'])) {
	    	$application_index = Swfy::$config['application_index'];
	    	if(class_exists($application_index)) {
            	Swfy::$config['application_index']::bootstrap($this->getRequestParams());
        	}
        }
	}

	/**
	 * run 执行
	 * @param  $request
	 * @param  $response
     * @throws \Exception
	 * @return void
	 */
	public function run($request, $response, $extend_data = null) {
	    try {
            parent::creatObject();
            $this->request = $request;
            $this->response = $response;
            $this->coroutine_id = CoroutineManager::getInstance()->getCoroutineId();
            Application::setApp($this);
            $this->_init();
            $this->_bootstrap();
            if(!$this->catchAll()) {
                // 路由调度执行
                $route = new HttpRoute($extend_data);
                $route->dispatch();
            }
        }catch (\Throwable $t) {
            throw new \Exception($t->getMessage());
        }finally {
            $this->clearStaticVar();
            $this->end();
            return;
        }
	}

	/**
	 * catchAll 捕捉拦截所有请求，进入维护模式
	 * @return boolean
	 */
	public function catchAll() {
		// 获取配置信息
		if(isset($this->config['catch_handle']) && $handle = $this->config['catch_handle']) {
            $this->is_end = true;
			if(is_array($handle)) {
				$this->response->header('Content-Type','application/json; charset=UTF-8');
				$this->response->end(json_encode($handle, JSON_UNESCAPED_UNICODE));
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
                    \Swoolefy\Core\Log\LogManager::getInstance()->{$action}($log);
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
		// 销毁当前的请求应用对象
		Application::removeApp();
		// 设置一个异常结束
        if(!$this->is_end) {
            @$this->response->end();
        }

	}

	//使用trait的复用特性
	use \Swoolefy\Core\AppTrait,\Swoolefy\Core\ServiceTrait;
}