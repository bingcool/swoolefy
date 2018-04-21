<?php
namespace protocol\websocket;
/**
 * 作为开放服务的接口模板，由用户定义,该文件将在服务第一次启动时由score/EventServer复制到protocol/webspcket下
 */

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
