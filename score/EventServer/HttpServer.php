<?php
namespace protocol\http;
/**
 * 作为开放服务的接口模板，由用户定义,该文件将在服务第一次启动时由score/EventServer复制到protocol/http下
 */

use Swoolefy\Core\Swfy;

class HttpServer extends \Swoolefy\Http\HttpAppServer {

	/**
	 * __construct 初始化
	 * @param array $config
	 */
	public function __construct(array $config=[]) {
		parent::__construct($config);
	}

	/**
	 * onPipeMessage 
	 * @param    object  $server
	 * @param    int     $src_worker_id
	 * @param    mixed   $message
	 * @return   void
	 */
	public function onPipeMessage($server, $src_worker_id, $message) {}

}	