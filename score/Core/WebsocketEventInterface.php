<?php
namespace Swoolefy\Core;

interface WebsocketEventInterface {
	
	public function onWorkerStart($server, $worker_id);

	public function onOpen($server, $request);

	public function onRequest($request, $response);

	public function onMessage($server, $frame);

	public function onTask($server, $task_id, $from_worker_id, $data);

	public function onFinish($server, $task_id, $data);

	public function onClose($server, $fd);
}
