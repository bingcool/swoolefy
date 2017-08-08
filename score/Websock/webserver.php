<?php
namespace Swoolefy\Websock;
use Swoole\WebSocket\Server as WebSockServer;
use Swoole\Process as swoole_process;

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
		'worker_num' => 1,    //worker process num
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


	public function __construct(array $config=[]) {

		$conf = array_merge($this->conf,$config);
		var_dump($conf);
		$this->webserver = new WebSockServer($this->host, $this->webPort);

		$this->webserver->set($conf);
	}

	public function start() {
		/**
		 * 启动worker进程监听回调，设置定时器
		 */
		$this->webserver->on('WorkerStart',function(WebSockServer $server, $worker_id){
			// 创建定时器
			$this->timer_id = swoole_timer_tick(3000,[$this,"timer_callback"]);

		});

		$this->webserver->on('request',function($request, $response) {
			$tpl = file_get_contents(__DIR__.'/../App/View/test.html');
			$response->end($tpl);
		});

		$this->webserver->on('open', function (WebSockServer $server, $request) {
			 	    
		});

		$this->webserver->on('message', function (WebSockServer $server, $frame) {

		});

		$this->webserver->on('close', function (WebSockServer $server, $fd) {
		    
		});

		$this->webserver->start();
	}
 
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

	public function callback_function(swoole_process $worker) {
	    $worker->exec('/bin/bash', array($this->monitorShellFile,$this->monitorPort));
	}
}

$websock = new Webserver();

$websock->start();