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

namespace Swoolefy\Rpc;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\Swoole;
use Swoolefy\Core\BaseServer;
use Swoolefy\Core\ServiceDispatch;
use Swoolefy\Core\HandlerInterface;

class RpcHandler extends Swoole implements HandlerInterface {

	/**
	 * $header length方式packet检测时，可以寄存请求包的信息，用于认证等
	 * @var array
	 */
	public $header = [];

	/**
	 * __construct 初始化
	 * @param array $config
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
	 * @param  mixed $recv
	 * @return void
	 */
	public function bootstrap($recv) {}

	/**
	 * run 完成初始化后,路由匹配和创建访问实例
	 * @param  int   $fd
	 * @param  mixed $payload
     * @throws \Throwable
	 * @return mixed
	 */
	public function run($fd, $payload, array $extend_data = []) {
	    try {
	        if($this->isWorkerProcess()) {
                if(BaseServer::isPackLength()) {
                    list($header, $body) = $payload;
                    $this->header = $header;
                }else if(BaseServer::isPackEof()) {
                	$body = $payload;
                	list($callable, $params) = $body;
                	if(count($callable) == 2) {
                		$ping = $callable[1];
                		$this->header['request_id'] = $ping;
                    }
                }
                if($this->ping()) {
                    $pong = ['pong', $this->header];
                    $data = \Swoolefy\Rpc\RpcServer::pack($pong);
                    Swfy::getServer()->send($fd, $data);
                    return;
                }
            }
            // 必须要执行父类的run方法
            parent::run($fd, $payload);
            // 当前进程worker进程
            if($this->isWorkerProcess()) {
                // packet_length_checkout方式
                if(BaseServer::isPackLength() || BaseServer::isPackEof()) {
                    if(is_array($body) && count($body) == 2) {
                        list($callable, $params) = $body;
                    }
                }else {
                	// TODO
                }
            }else {
                // 任务task进程
                $is_task_process = true;
                list($callable, $params) = $payload;
            }
            if($callable) {
                $dispatch = new ServiceDispatch($callable, $params, $this->header);
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
	 * ping 心跳检测
	 * @return   
	 */
	public function ping() {
		if(in_array($this->header['request_id'], ['ping', 'PING'])) {
			return true;
		}
		return false;
	}

	/**
	 * author 认证
	 * @return 
	 */
	public function author() {}

}