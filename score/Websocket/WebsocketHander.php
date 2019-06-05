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

namespace Swoolefy\Websocket;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\Swoole;
use Swoolefy\Core\Application;
use Swoolefy\Core\ServiceDispatch;
use Swoolefy\Core\HanderInterface;
use Swoolefy\Core\Coroutine\CoroutineManager;

class WebsocketHander extends Swoole implements HanderInterface {
	/**
	 * __construct 初始化
	 * @param    array  $config
	 */
	public function __construct(array $config=[]) {
		parent::__construct($config);
	}

	/**
	 * init 当执行run方法时,首先会执行init->bootstrap
	 * @param  mixed  $recv
	 * @return void       
	 */
	public function init($recv) {}

	/**
	 * bootstrap 当执行run方法时,首先会执行init->bootstrap
	 * @param  mixed  $recv
	 * @return void
	 */
	public function bootstrap($recv) {}


	/**
	 * run 服务调度，创建访问实例，处理String数据
	 * @param  int   $fd
	 * @param  mixed $recv
	 * @return mixed
	 */
	public function run($fd, $recv, array $extend_data = []) {
	    try {
	        // 心跳
	        if($this->isWorkerProcess()) {
                $recv = array_values(json_decode($recv, true));
                if(is_array($recv) && count($recv) == 3) {
                    list($service, $event, $params) = $recv;
                }

                if($this->ping($event)) {
                    $data = 'pong';
                    Swfy::getServer()->push($fd, $data, $opcode = 1, $finish = true);
                    return;
                }
            }
            // 必须要执行父类的run方法,$recv是json字符串,boostrap函数中可以接收做一些引导处理
            parent::run($fd, $recv);
            // worker进程
            if($this->isWorkerProcess()) {
                if($service && $event) {
                    $callable = [$service, $event];
                }
            }else {
                // 任务task进程
                $is_task_process = true;
                list($callable, $params) = $recv;
            }

            // 控制器实例
            if($callable) {
                $Dispatch = new ServiceDispatch($callable, $params);
                if(isset($is_task_process) && $is_task_process == true) {
                    list($from_worker_id, $task_id, $task) = $extend_data;
                    $Dispatch->setFromWorkerIdAndTaskId($from_worker_id, $task_id, $task);
                }
                $Dispatch->dispatch();
            }

        } finally {
            // 必须执行
            parent::end();
            return;
        }

	}

	/**
	 * handleBinary 处理二进制数据
	 * @param  int   $fd
	 * @param  array $recv
     * @throws \Exception
	 * @return void
	 */
	public function handleBinary($fd, $recv) {
	    try {
            // 必须要执行父类的run方法,注意$recv是数据，第三个元素是二进制数据，为节省内存，不传这个元素到boostrap函数中
            $new_recv = is_array($recv) ? array_slice($recv, 0, 2) : [];
            parent::run($fd, $new_recv);
            // worker进程
            if($this->isWorkerProcess()) {
                if(is_array($recv) && count($recv) == 3) {
                    list($service, $event, $buffer) = $recv;
                }
                if($service && $event) {
                    $callable = [$service, $event];
                }

            }else {
                // 任务task进程,不处理二进制数据
                throw new \Exception("Task process can not handle binary data");
            }

            // 控制器实例
            if($callable && $buffer) {
                $Dispatch = new ServiceDispatch($callable, $buffer);
                $Dispatch->dispatch();
            }

        } finally {
            // 必须执行
            if(!$this->is_defer) {
                parent::end();
            }
            return;
        }
	}

	/**
	 * ping 
	 * @param    string   $evnet
	 * @return   boolean
	 */
	public function ping(string $evnet) {
		if(strtolower($evnet) == 'ping') {
			return true;
		}
		return false;
	}

	/**
	 * author 认证
	 * @return void
	 */
	public function author() {}
}

