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

namespace protocol\udp;

use Swoolefy\Core\Swfy;

class UdpEventServer extends \Swoolefy\Udp\UdpEventServer {

    /**
     * __construct 初始化
     * @param array $config
     * @throws \Exception
     */
	public function __construct(array $config=[]) {
		parent::__construct($config);
	}

	/**
	 * onWorkerStart worker进程启动时回调处理
	 * @param  object $server
	 * @param  int    $worker_id
	 * @return void       
	 */
	public function onWorkerStart($server, $worker_id) {}

	/**
	 * onFinish 
	 * @param  object $server
	 * @param  int    $task_id
	 * @param  mixed  $data
	 * @return mixed
	 */
	public function onFinish($server, $task_id, $data) {}

	/**
	 * onPipeMessage 
	 * @param object $server
	 * @param int $src_worker_id
	 * @param mixed $message
	 * @return void
	 */
	public function onPipeMessage($server, $from_worker_id, $message) {}

}
