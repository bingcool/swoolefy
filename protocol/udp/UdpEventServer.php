<?php
namespace protocol\udp;
/**
 * 作为开放服务的接口模板，由用户定义，该文件将在服务第一次启动时由score/EventServer复制到protocol/udp下
 */

use Swoolefy\Core\Swfy;

class UdpEventServer extends \Swoolefy\Udp\UdpEventServer {
	/**
	 * __construct 初始化
	 * @param array $config
	 */
	public function __construct(array $config=[]) {
		parent::__construct($config);
	}

	public function onWorkerStart($server, $worker_id) {}


	public function onFinish($server, $task_id, $data) {}

}
