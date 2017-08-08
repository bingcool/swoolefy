<?php
namespace Swoolefy\Websocket;
include_once "../../vendor/autoload.php";

use Swoole\WebSocket\Server as WebSockServer;
use Swoole\Process as swoole_process;
use Swoolefy\Websocket\Dispatch;

class Webserver {

	const WEBSOCKET_STATUS = 3;
	/**
	 * $webserver
	 * @var null
	 */
	public $webserver = null;

	/**
	 * $conf
	 * @var array
	 */
	public $conf = [
		'reactor_num' => 1, //reactor thread num
		'worker_num' => 2,    //worker process num
		'max_request' => 1,
		'daemonize' => 0
	];

	public $host = "0.0.0.0";

	/**
	 * $webPort
	 * @var integer
	 */
	public $webPort = 9502;

	/**
	 * $timer_id
	 * @var null
	 */
	private $timer_id = null;

	/**
	 * $monitorShellFile
	 * @var [type]
	 */
	public $monitorShellFile = __DIR__."/../Shell/swoole_monitor.sh";

	/**
	 * $monitorPort,监听的swoole服务的端口，与autoreload监听端口一致
	 * @var integer
	 */
	public $monitorPort = 9501;

	static $test = 0;

	public function __construct(array $config=[]) {

		$this->conf = array_merge($this->conf,$config);

		$this->webserver = new WebSockServer($this->host, $this->webPort);

		$this->webserver->set($this->conf);
	}

	public function start() {
		/**
		 * start回调
		 */
		$this->webserver->on('Start',function(WebSockServer $server) {
			$this->setMasterProcessName();
		});

		/**
		 * managerstart回调
		 */
		$this->webserver->on('ManagerStart',function(WebSockServer $server) {
			$this->setManagerProcessName();
		});

		/**
		 * 启动worker进程监听回调，设置定时器
		 */
		$this->webserver->on('WorkerStart',function(WebSockServer $server, $worker_id){
			// 创建定时器
			$this->timer_id = swoole_timer_tick(3000,[$this,"timer_callback"]);
			// 重新设置进程名称
			$this->setWorkerProcessName($worker_id);

		});

		/**
		 * 接受http请求
		 */
		$this->webserver->on('request',function($request, $response) {
			if($request->server['path_info'] == '/favicon.ico' || $request->server['request_uri'] == '/favicon.ico') {
            		return $response->end();
       		}
       		self::$test++;
       		var_dump(self::$test);
       		// 请求调度
			call_user_func_array(array(new Dispatch(), "dispatch"), array($request, $response));
		});

		$this->webserver->on('message', function (WebSockServer $server, $frame) {

		});

		$this->webserver->on('close', function (WebSockServer $server, $fd) {
		    
		});

		$this->webserver->start();
	}

	/**
	 * timer_callback
	 */
	public function timer_callback() {
		$process_timer = new swoole_process([$this,'callback_function'], true);
		$process_pid = $process_timer->start();	
		$pid = intval($process_timer->read());
		swoole_process::wait();

		if(!is_int($pid) || !$pid) {
			foreach($this->webserver->connections as $fd) {
				$fdInfo = $this->webserver->connection_info($fd);
				// 判断是否是websocket连接
				if($fdInfo["websocket_status"] == self::WEBSOCKET_STATUS) {
					$this->webserver->push($fd,json_encode(['code'=>"01",'msg'=>"swoole停止",'pid'=>'']));
				}	
			}
			return;
		}

		// 循环推送给连接上的所有客户端
		foreach($this->webserver->connections as $fd) {
			$fdInfo = $this->webserver->connection_info($fd);
			// 判断是否是websocket连接
			if($fdInfo["websocket_status"] == self::WEBSOCKET_STATUS) {
				$this->webserver->push($fd,json_encode(['code'=>'00','msg'=>"swoole正常",'pid'=>$pid]));
			}	
		}		
	}

	/**
	 * callback_function
	 * @param    swoole_process $worker
	 */
	public function callback_function(swoole_process $worker) {
	    $worker->exec('/bin/bash', array($this->monitorShellFile,$this->monitorPort));
	}

	/**
	 * setWorkerProcessName设置worker进程名称
	 */
	private function setWorkerProcessName($worker_id) {
		// 设置worker的进程
		if($worker_id >= $this->conf['worker_num']) {
            swoole_set_process_name("php-monitor-task_worker".$worker_id);
        }else {
            swoole_set_process_name("php-monitor-worker".$worker_id);
        }

	}

	/**
	 * setMasterProcessName设置主进程名称
	 */
	private function setMasterProcessName() {
		swoole_set_process_name("php-monitor-master");
	}

	/**
	 * setManagerProcessName设置管理进程名称
	 */
	private function setManagerProcessName() {
		swoole_set_process_name("php-monitor-manager");
	}

}

$websock = new Webserver();

$websock->start();