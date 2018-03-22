<?php
namespace Swoolefy\Core;

/**
 * rpc 定义接口
 */
interface EventInterface {
	
	public function onWorkerStart($server, $worker_id);

	public function onConnet($server, $fd);

	public function onReceive($server, $fd, $reactor_id, $data);

	public function onTask($server, $task_id, $from_worker_id, $data);

	public function onFinish($server, $task_id, $data);

	public function onClose($server, $fd);
}

/**
 * websocket 定义接口
 */
interface WebsocketEventInterface {
	
	public function onWorkerStart($server, $worker_id);

	public function onOpen($server, $request);

	public function onRequest($request, $response);

	public function onMessage($server, $frame);

	public function onTask($server, $task_id, $from_worker_id, $data);

	public function onFinish($server, $task_id, $data);

	public function onClose($server, $fd);
}

/**
 * udp 定义接口
 */
interface UdpEventInterface {
	public function onWorkerStart($server, $worker_id);

	public function onPack($server, $data, $clientInfo);

	public function onTask($server, $task_id, $from_worker_id, $data);

	public function onFinish($server, $task_id, $data);

}
