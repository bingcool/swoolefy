<?php
namespace Swoolefy\Rpc;

use Swoolefy\Core\Swfy;
use Swoolefy\Tcp\TcpServer;

// 如果直接通过php RpcServer.php启动时，必须include的vendor/autoload.php
if(isset($argv) && $argv[0] == basename(__FILE__)) {
	include_once '../../vendor/autoload.php';
}

class RpcServer extends TcpServer implements \Swoolefy\Core\EventInterface {

	public function __construct(array $config=[]) {
		// 获取当前服务文件配置
		$config = array_merge(
				include(__DIR__.'/config.php'),
				$config
			);
		parent::__construct($config);

		$this->serverName = 'Rpc';
	}

	public function onWorkerStart($server, $worker_id) {

	}

	public function onConnet($server, $fd) {

	}

	public function onReceive($server, $fd, $reactor_id, $data) {
		swoole_unpack(self::$service)->run($fd, $data);
	}

	/**
	 * onTask 任务处理函数调度
	 * @param    object   $server
	 * @param    int      $task_id
	 * @param    int      $from_id
	 * @param    mixed    $data
	 * @return   void
	 */
	public function onTask($task_id, $from_id, $data) {
		list($class, $taskData) = $data;		
		// 实例任务
		if(is_string($class)) {
			
		}else if(is_array($class)) {
			// 类静态方法调用任务
			call_user_func_array($class, [$taskData]);
		}else {
			swoole_unpack(self::$service)->run($class, $taskData);
		}
		
		return ;
	}

	/**
	 * onFinish 异步任务完成后调用
	 * @param    int      $task_id
	 * @param    mixed    $data
	 * @return   void
	 */
	public function onFinish($task_id, $data) {
		list($callable, $taskData) = $data;
		if(!is_array($callable) || !is_array($taskData)) {
			return false;
		}
		call_user_func_array($callable, [$taskData]);
		return true;
	}

	public function onClose($server, $fd) {

	}

	/**
	 * registerRpc 
	 * @return   [type]        [description]
	 */
	public function registerRpc() {
		$this->tcp_client = new \swoole_client(SWOOLE_SOCK_TCP, SWOOLE_SOCK_ASYNC);
		//注册连接成功回调
		$this->tcp_client->on("connect", function($cli){
		    // 
		});

		//注册数据接收回调
		$this->tcp_client->on("receive", function($cli, $data){

		});

		//注册连接失败回调
		$this->tcp_client->on("error", function($cli){
		});

		//注册连接关闭回调
		$this->tcp_client->on("close", function($cli){
		});

		//发起连接
		$this->tcp_client->connect('127.0.0.1', 9998, 0.5);
	}	
}

if(isset($argv) && $argv[0] == basename(__FILE__)) {
	$rpcserver = new RpcServer();
	$rpcserver->start();
}