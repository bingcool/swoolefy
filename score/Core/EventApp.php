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

use Swoolefy\Core\Application;
use Swoolefy\Core\EventController;

class EventApp {
	/**
	 * $event_app 事件处理应用对象
	 * @var EventController
	 */
	protected $event_app;

	/**
	 * $is_call 单例是否已调用执行函数，只能调用一次执行函数，即单例只能有一个入口执行函数
	 * @var boolean
	 */
    protected $is_call = false;

	/**
	 * registerApp 注册事件处理应用对象，注册一次处理事件
	 * 可用于onConnect, onOpen, onPipeMessage,onHandShake, onClose这些协程回调中，每次回调都会创建一个协程
	 * 而onFinish回调也可以使用这个注册，但是这个是阻塞执行的，不会创建协程，所以不会产生协程id，将返回cid=-1（那么重定义为cid_task_process），所以在继承的业务类中onFinish不能使用协程API.
	 * 例如在close事件，App\Event\Close业务类需要继承于\Swoolefy\Core\EventController
	 *
	 * public function onClose($server, $fd) {
		   // 只需要注册一次就好
		   (new \Swoolefy\Core\EventApp())->registerApp(\App\Event\Close::class, $server, $fd)->close();
	 *  }
	 *
	 * 那么处理类
	 * class Close extends EventController {
	     	// 继承于EventController，可以传入可变参数
			public function __construct($server, $fd) {
				// 必须执行父类__construct()
				parent::__construct();
			}

			public function close() {
				//TODO
			}
	*  }
	*  同时go创建协程中，创建应用实例可以使用这个类注册实例，\App\Event\Gocoroutine继承于\Swoolefy\Core\EventController
	*  go(function() {
			$app = (new \Swoolefy\Core\EventApp)->registerApp(\App\Event\Gocoroutine::class);
			$app->test();
		});
     * 也可以利用闭包形式,最后一个函数是传进来的闭包函数的形参
     * go(function() {
            (new \Swoolefy\Core\EventApp)->registerApp(function($name) {
                var_dump($name); //输出bingcool
            },'bingcool');
        });
     *
	 * @param  string $class
     * @param  array  $args
     * @throws \Throwable
	 * @return $this
	 */
	public function registerApp($class, array $args = []) {
	    if($class instanceof \Closure) {
            $this->event_app = new EventController(...$args);
            try {
                $class->call($this->event_app, ...$args);
            }catch(\Throwable $throwable) {
                BaseServer::catchException($throwable);
            }finally {
            	if(!$this->event_app->isDefer()) {
                    $this->event_app->end();
                }
            }
        }else {
	        do{
                if(is_string($class)) {
                    $this->event_app = new $class(...$args);
                }else if(is_object($class)) {
                    $this->event_app = $class;
                }
            	break;
            }while(0);

            if(!($this->event_app instanceof EventController)) {
                $class_name = get_class($this->event_app);
                unset($this->event_app);
                throw new \Exception("{$class_name} must extends \Swoolefy\Core\EventController, please check it");
            }
        }
		return $this;
	}

	/**
	 * getAppCid 获取当前应用实例的协程id
	 * @return  string
	 */
	public function getCid() {
		return $this->event_app->getCid();
	}

    /**
     * @return EventController
     */
	public function getEventApp() {
	    return $this->event_app;
    }

	/**
	 * __call 在协程编程中可直接使用try/catch处理异常。但必须在协程内捕获，不得跨协程捕获异常。
    当协程退出时，发现有未捕获的异常，将引起致命错误。
     * @param  string $action
	 * @param  array  $args
     * @return  mixed
     * @throws \Exception
	 */
	public function __call(string $action, $args = []) {
		try{
			if($this->is_call) {
                $class_name = get_class($this->event_app);
				throw new \Exception("{$class_name} Single Coroutine Instance only be called one method, you haved called");
			}
            try {
                $this->is_call = true;
                $result = $this->event_app->$action(...$args);
                return $result;
            }catch(\Throwable $throwable) {
			    throw $throwable;
            }finally {
                if(!$this->event_app->isDefer()) {
                    $this->event_app->end();
                }
            }
		}catch(\Throwable $throwable) {
            BaseServer::catchException($throwable);
		}
	}

	/**
	 * __destruct
	 */
	public function __destruct() {
		$cid = null;
		if(is_object($this->event_app)) {
			$cid = $this->event_app->getCid();
			unset($this->event_app);
		}
		Application::removeApp($cid);
	}
}