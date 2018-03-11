<?php
namespace Swoolefy\Rpc;

use Swoolefy\Core\Swfy;
use Swoolefy\Tcp\TcpServer;
use Swoolefy\Core\EventInterface;

// 如果直接通过php RpcServer.php启动时，必须include的vendor/autoload.php
if(isset($argv) && $argv[0] == basename(__FILE__)) {
	include_once '../../vendor/autoload.php';
}

class RpcServer extends TcpServer implements EventInterface {
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
		$this->serverName = 'Rpc';
	}

	/**
	 * onWorkerStart worker进程启动时回调处理
	 * @param  object $server
	 * @param  int    $worker_id
	 * @return void       
	 */
	public function onWorkerStart($server, $worker_id) {}

	/**
	 * onConnet socket连接上时回调函数
	 * @param  object $server
	 * @param  int    $fd    
	 * @return void        
	 */
	public function onConnet($server, $fd) {}

	/**
	 * onReceive 接收数据时的回调处理，$data是一个完整的数据包，底层已经封装好，只需要配置好，直接使用即可
	 * @param  object $server
	 * @param  int    $fd
	 * @param  int    $reactor_id
	 * @param  mixed  $data
	 * @return mixed
	 */
	public function onReceive($server, $fd, $reactor_id, $data) {
		swoole_unpack(self::$service)->run($fd, $data);
	}

	/**
	 * onTask 任务处理函数调度
	 * @param   object  $server
	 * @param   int     $task_id
	 * @param   int     $from_id
	 * @param   mixed   $data
	 * @return  void
	 */
	public function onTask($task_id, $from_id, $data) {
		list($callable, $taskData, $fd) = $data;		
		swoole_unpack(self::$service)->run($fd, [$callable, $taskData]);
		return ;
	}

	/**
	 * onFinish 异步任务完成后调用
	 * @param    int     $task_id
	 * @param    mixed   $data
	 * @return   void
	 */
	public function onFinish($task_id, $data) {}

	/**
	 * onClose tcp连接关闭时回调函数
	 * @param  object $server
	 * @param  int    $fd    
	 * @return void
	 */
	public function onClose($server, $fd) {}

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