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

namespace Swoolefy\Websocket;

use Swoolefy\Core\Application;
use Swoolefy\Core\Swfy;
use Swoolefy\Core\Swoole;
use Swoolefy\Core\ServiceDispatch;
use Swoolefy\Core\HandlerInterface;

class WebsocketHandler extends Swoole implements HandlerInterface {

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
	 * @param  mixed $payload
     * @throws \Throwable
	 * @return mixed
	 */
	public function run($fd, $payload, array $extend_data = []) {
	    try {
	        // heart
	        if($this->isWorkerProcess()) {
                $payload = array_values(json_decode($payload, true) ?? []);
                if(is_array($payload) && count($payload) == 3) {
                    list($service, $event, $params) = $payload;
                }else {
                    return Swfy::getServer()->push($fd, json_encode($this->errorMsg('Websocket Params Missing')), $opcode = 1, $finish = true);
                }

                if($this->ping($event)) {
                    $data = json_encode(['pong'=>1,'ok'=>1]);
                    return Swfy::getServer()->push($fd, $data, $opcode = 1, $finish = true);
                }
            }
            // 必须要执行父类的run方法,$recv是json字符串,bootstrap函数中可以接收做一些引导处理
            parent::run($fd, $payload);
            // worker进程
            if($this->isWorkerProcess()) {
                if($service && $event) {
                    $callable = [$service, $event];
                }
            }else {
                // 任务task进程
                $is_task_process = true;
                list($callable, $params) = $payload;
            }

            if($callable) {
                $dispatch = new ServiceDispatch($callable, $params);
                if(isset($is_task_process) && $is_task_process === true) {
                    list($from_worker_id, $task_id, $task) = $extend_data;
                    $dispatch->setFromWorkerIdAndTaskId($from_worker_id, $task_id, $task);
                }
                $dispatch->dispatch();
            }

        }catch (\Throwable $throwable) {
            throw $throwable;
        } finally {
            // 必须执行
            if(!$this->is_defer) {
                parent::end();
            }
        }

	}

	/**
	 * ping 
	 * @param    string   $evnet
	 * @return   boolean
	 */
	public function ping(string $event) {
		if(strtolower($event) == 'ping') {
			return true;
		}
		return false;
	}

    /**
     * @param string $errorMethod
     * @param string $msg
     * @return array
     */
	private function errorMsg($msg = '') {
        if(Swfy::isWorkerProcess()) {
            $errorMsg = Application::buildResponseData(500, $msg);
        }
        return $errorMsg ?? [];
    }

	/**
	 * author 认证
	 * @return void
	 */
	public function author() {}
}

