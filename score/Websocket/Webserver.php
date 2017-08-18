<?php
namespace Swoolefy\Websocket;
include_once "../../vendor/autoload.php";

use Swoole\WebSocket\Server as WebSockServer;
use Swoole\Process as swoole_process;
use Swoolefy\App\Application;
use Swoolefy\Core\Base;
use Swoolefy\Core\Swfy;
use Swoole\Http\Request;
use Swoole\Http\Response;

class Webserver extends Base {

	/**
	 * 定义进程名称
	 */
	const MASTER_PROCESS_NAME = 'php-monitor-master';

	const MANAGER_PROCESS_NAME = 'php-monitor-manager';

	const WORKER_PROCESS_NAME = 'php-monitor';

	const WWW_USER = 'www';

	const INCLUDES = [
		__DIR__.'/Config/config.php',

	];
	/**
	 * websocket连接状态
	 */
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
	static $conf = [
		'reactor_num' => 1, //reactor thread num
		'worker_num' => 2,    //worker process num
		'max_request' => 1000,
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

	public static $App = null;

	public function __construct(array $config=[]) {

		self::$conf = array_merge(self::$conf,$config);

		$this->webserver = new WebSockServer($this->host, $this->webPort);

		$this->webserver->set(self::$conf);
	}

	public function start() {
		/**
		 * start回调
		 */
		$this->webserver->on('Start',function(WebSockServer $server) {
			self::setMasterProcessName(self::MASTER_PROCESS_NAME);
		});

		/**
		 * managerstart回调
		 */
		$this->webserver->on('ManagerStart',function(WebSockServer $server) {
			self::setManagerProcessName(self::MANAGER_PROCESS_NAME);
		});

		/**
		 * 启动worker进程监听回调，设置定时器
		 */
		$this->webserver->on('WorkerStart',function(WebSockServer $server, $worker_id){
			// 加载文件
			self::startInclude(self::INCLUDES);
			// 重新设置进程名称
			self::setWorkerProcessName(self::WORKER_PROCESS_NAME, $worker_id, self::$conf['worker_num']);
			// 设置worker工作的进程组
			self::setWorkerUserGroup(self::WWW_USER);
			// 创建定时器,只在第一个worker中创建，否则会有多个worker推送信息
			if($worker_id == 0) {
				$this->timer_id = swoole_timer_tick(3000,[$this,"timer_callback"]);
			}

			// 初始化整个应用对象
			$config = Application::init();
			self::$App = swoole_pack(Application::getInstance($config));
		});

		/**
		 * 接受http请求
		 */
		$this->webserver->on('request',function(Request $request, Response $response) {
			// google浏览器会自动发一次请求/favicon.ico,在这里过滤掉
			if($request->server['path_info'] == '/favicon.ico' || $request->server['request_uri'] == '/favicon.ico') {
            		return $response->end();
       		}
       		//请求调度
       		// $process_test = new swoole_process(function(swoole_process $process_worker) use($request, $response) {
       		// 	call_user_func_array(array(new App, "dispatch"), array($request, $response));
       		// },false);
       		// $process_pid = $process_test->start();
       		// swoole_process::wait();
       		Swfy::$server = $this->webserver;
       		// var_dump(Swfy::$server);
			swoole_unpack(self::$App)->dispatch($request, $response);
			// call_user_func_array([swoole_unpack(self::$App), "dispatch"], [$request, $response]);
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

}

$websock = new Webserver();
$websock->start();