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

namespace protocol\websocket;

use Swoolefy\Core\Swfy;

class WebsocketEventServer extends \Swoolefy\Websocket\WebsocketEventServer {
	/**
	 * __construct 初始化
	 * @param array $config
	 */
	public function __construct(array $config = []) {
		parent::__construct($config);
	}

	/**
	 * onWorkerStart worker启动函数处理
	 * @param    object  $server
	 * @param    int    $worker_id
	 * @return   void
	 */
	public function onWorkerStart($server, $worker_id) {}

	/**
	 * onOpen 
	 * @param    object  $server
	 * @param    object  $request
	 * @return   void
	 */
	public function onOpen($server, $request) {}

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
	 * @param    object  $server
	 * @param    int     $src_worker_id
	 * @param    mixed   $message
	 * @return   void
	 */
	public function onPipeMessage($server, $from_worker_id, $message) {}

	/**
	 * onClose 连接断开处理
	 * @param    object  $server
	 * @param    int     $fd
	 * @return   void
	 */
	public function onClose($server, $fd) {}

}
