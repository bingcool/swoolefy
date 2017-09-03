<?php
namespace Swoolefy\Core;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\Application;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Swoolefy\Tool\ArrayHelper\ArrayForHelp;
use Swoolefy\Core\HttpRoute;

class App {
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
	 * $route
	 * @var null
	 */
	public  $route = null;
	/**
	 * __construct
	 * @param $config 应用层配置
	 */
	public function __construct(array $config=[]) {
		$this->config = $config;
		// 注册错误处理事件
		register_shutdown_function('Swoolefy\Core\App::fatalError');
      	set_exception_handler('Swoolefy\Core\App::appException');
	}

	/**
	 * init 初始化函数
	 * @return void
	 */
	public function init() {
		// 初始化启动session
		if(!isset($this->config['session_start']) || (isset($this->config['session_start']) && $this->config['session_start'] === true)) {
			if(!isset($_SESSION)) {
				session_start();
			}
		}

		try {

		}catch(\Exception $e) {

		}

	}

	/**
	 * run
	 * @param  $request
	 * @param  $response
	 * @return void
	 */
	public function run($request, $response) {
		$this->init();
		// 赋值对象
		$this->request = $request;
		$this->response = $response;

		// 判断是否是在维护模式
		if(!$this->catch()) {
			// 执行应用
			Application::$app = $this;
			$route = new HttpRoute();
			$route->dispatch();
		}
	}

	/**
	 * catch 捕捉拦截所有请求，进入维护模式
	 * @return void
	 */
	public function catch() {
		// 获取配置信息
		if(isset($this->config['catch_all_info']) && $info = $this->config['catch_all_info']) {
			if(is_array($info)) {
				$this->response->header('Content-Type','application/json; charset=UTF-8');
				$this->response->end(json_encode($info));
			}else {
				$this->response->gzip(1);
				$this->response->header('Content-Type','text/html; charset=UTF-8');
				$this->response->end($info);
			}

			return true;
		}

		return false;
	}

	/**
	 * 致命错误捕获
	 * @return 
	 */
    public static function fatalError() {
        if ($e = error_get_last()) {
            switch($e['type']){
              case E_ERROR:
              case E_PARSE:
              case E_CORE_ERROR:
              case E_COMPILE_ERROR:
              case E_USER_ERROR:  
                @ob_end_clean();
                self::halt($e);
                break;
            }
        }
    }

    /**
     * 错误输出
     * @param  $error 错误
     * @return void
     */
    public static function halt($error) {
        $Log = new \Swoolefy\Tool\Log('Application',APP_PATH.'/runtime.log');
        $Log->addError($error['message']);
    }

	/**
     * 自定义异常处理
     * @param mixed $e 异常对象
     */
    public static function appException($e) {
        $error = array();
        $error['message']   =   $e->getMessage();
        $trace              =   $e->getTrace();
        if('E'==$trace[0]['function']) {
            $error['file']  =   $trace[0]['file'];
            $error['line']  =   $trace[0]['line'];
        }else{
            $error['file']  =   $e->getFile();
            $error['line']  =   $e->getLine();
        }
        $error['trace']     =   $e->getTraceAsString();
        self::halt($error);
    }

	//使用trait的复用特性
	use \Swoolefy\Core\AppTrait;
}