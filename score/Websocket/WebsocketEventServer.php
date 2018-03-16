<?php
namespace Swoolefy\Websocket;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\WebsocketEventInterface;
use Swoolefy\Websocket\WebsocketServer;

// 如果直接通过php RpcServer.php启动时，必须include的vendor/autoload.php
if(isset($argv) && $argv[0] == basename(__FILE__)) {
	include_once '../../vendor/autoload.php';
}

class WebsocketEventServer extends WebsocketServer implements WebsocketEventInterface {
	/**
	 * __construct 初始化
	 * @param array $config
	 */
	public function __construct(array $config=[]) {
		// 获取当前服务文件配置
		$config = array_merge(
				include(__DIR__.'/config.php'),
				$config
			);
		parent::__construct($config);
		// 设置当前的服务名称
	}


	public function onWorkerStart($server, $worker_id) {

	}

	public function onOpen($server, $request) {}

	public function onRequest($request, $response) {
		swoole_unpack(self::$App)->run($request, $response);
	}

	public function onMessage($server, $frame) {
		$fd = $frame->fd;
		$data = $frame->data;
		$opcode = $frame->opcode;
		$finish = $frame->finish;
		if($finish) {
			// 数据接收是否完整
			swoole_unpack(self::$service)->run($fd, $data);
		}else {
			// 断开连接
			
		}
		
	}

	public function onTask($server, $task_id, $from_worker_id, $data) {
		list($callable, $taskData, $fd) = $data;		
		swoole_unpack(self::$service)->run($fd, [$callable, $taskData]);
		return ;
	}

	public function onFinish($server, $task_id, $data) {

	}

	public function onClose($server, $fd) {

	}

}

if(isset($argv) && $argv[0] == basename(__FILE__)) {
	$websocketserver = new WebsocketEventServer();
	$websocketserver->start();
}