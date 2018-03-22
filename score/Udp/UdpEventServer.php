<?php
namespace Swoolefy\Udp;

include_once SWOOLEFY_CORE_ROOT_PATH.'/EventInterface.php';

use Swoolefy\Core\Swfy;
use Swoolefy\Udp\UdpServer;
use Swoolefy\Core\UdpEventInterface;

// 如果直接通过php RpcServer.php启动时，必须include的vendor/autoload.php
if(isset($argv) && $argv[0] == basename(__FILE__)) {
	include_once '../../vendor/autoload.php';
}


class UdpEventServer extends UdpServer implements UdpEventInterface {
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
		self::$serverName = SWOOLEFY_UDP;
	}

	public function onWorkerStart($server, $worker_id) {

	}

	public function onPack($server, $data, $clientInfo) {
		swoole_unpack(self::$service)->run($data, $clientInfo);
	}

	public function onTask($server, $task_id, $from_worker_id, $data) {

	}

	public function onFinish($server, $task_id, $data) {

	}


}

if(isset($argv) && $argv[0] == basename(__FILE__)) {
	$udpserver = new UdpEventServer();
	$udpserver->start();
}