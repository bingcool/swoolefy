<?php
namespace Swoolefy\Core;

interface EventInterface {
	
	public function onWorkerStart($server, $worker_id);

	public function onConnet($server, $fd);

	public function onReceive($server, $fd, $reactor_id, $data);

	public function onTask($task_id, $from_id, $data);

	public function onFinish($task_id, $data);

	public function onClose($server, $fd);
}