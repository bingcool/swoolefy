<?php
namespace Swoolefy\Websocket;

use Swoole\WebSocket\Server as websocket_server;
use Swoolefy\Core\BaseServer;
use Swoole\Server as tcp_server;
use Swoolefy\Core\Swfy;
use Swoole\Http\Request;
use Swoole\Http\Response;

// 如果直接通过php WebsocketServer.php启动时，必须include的vendor/autoload.php
if(isset($argv) && $argv[0] == basename(__FILE__)) {
	include_once '../../vendor/autoload.php';
}

class WebsocketServer extends BaseServer {
	/**
	 * $setting
	 * @var array
	 */
	public static $setting = [
		'reactor_num' => 1, //reactor thread num
		'worker_num' => 2,    //worker process num
		'max_request' => 5,
		'task_worker_num' =>1,
		'task_tmpdir' => '/dev/shm',
		'daemonize' => 0,
		'log_file' => __DIR__.'/log.txt',
		'pid_file' => __DIR__.'/server.pid',
	];

	/**
	 * $App
	 * @var null
	 */
	public static $App = null;

	/**
	 * $webserver
	 * @var null
	 */
	public $webserver = null;

	/**
	 * $tcpserver 
	 * @var null
	 */
	public $tcpserver = null;

	/**
	 * $tcpclient 
	 * @var null
	 */
	public $tcp_client = null;

	/**
	 * $gateway_config 
	 * @var array
	 */
	public $rpc_config = [
		['host'=>'127.0.0.1','port'=>9999]
	];


	public $channel = null;

	/**
	 * $startctrl
	 * @var null
	 */
	public static $startCtrl = null;

	/**
	 * __construct
	 * @param array $config
	 */
	public function __construct(array $config=[]) {
		self::clearCache();
		self::$config = array_merge(
					include(__DIR__.'/config.php'),
					$config
			);
		self::$server = $this->webserver = new websocket_server(self::$config['host'], self::$config['port']);
		self::$config['setting'] = self::$setting = array_merge(self::$setting, self::$config['setting']);
		$this->webserver->set(self::$setting);
		parent::__construct();

		// 监听多一个内部的TCP端口
		$this->tcpserver = $this->webserver->addListener('0.0.0.0', self::$config['tcp_port'], SWOOLE_SOCK_TCP);
		$this->tcpserver->set(self::$config['tcp_setting']);

		// 创建一个channel通道
		$this->channel = new \Swoole\Channel(1024 * 256);

		// 初始化启动类
		self::$startCtrl = isset(self::$config['start_init']) ? self::$config['start_init'] : 'Swoolefy\\Websocket\\StartInit';
		
	}

	public function start() {
		/**
		 * start回调
		 */
		$this->webserver->on('Start',function(websocket_server $server) {
			// 重新设置进程名称
			self::setMasterProcessName(self::$config['master_process_name']);
			// 启动的初始化函数
			self::$startCtrl::start($server);
		});
		/**
		 * managerstart回调
		 */
		$this->webserver->on('ManagerStart',function(websocket_server $server) {
			// 重新设置进程名称
			self::setManagerProcessName(self::$config['manager_process_name']);
			// 启动的初始化函数
			self::$startCtrl::managerStart($server);
		});

		/**
		 * 启动worker进程监听回调，设置定时器
		 */
		$this->webserver->on('WorkerStart',function(websocket_server $server, $worker_id) {
			// 记录主进程加载的公共files,worker重启不会在加载的
			self::getIncludeFiles('websocket');
			// 重启worker时，清空字节cache
			self::clearCache();
			// 重新设置进程名称
			self::setWorkerProcessName(self::$config['worker_process_name'], $worker_id, self::$setting['worker_num']);
			// 设置worker工作的进程组
			self::setWorkerUserGroup(self::$config['www_user']);
			// 启动时提前加载文件
			self::startInclude();
			// 记录worker的进程worker_pid与worker_id的映射
			self::setWorkersPid($worker_id,$server->worker_pid);
			// 超全局变量server
       		Swfy::$server = $this->webserver;
       		Swfy::$config = self::$config;
       		// 初始化整个应用对象,http请求设置
       		if(self::$config['accept_http'] || self::$config['accept_http'] == 'true') {
       			is_null(self::$App) && self::$App = swoole_pack(self::$config['application_index']::getInstance($config=[]));
       		}
			// 启动的初始化函数
			self::$startCtrl::workerStart($server,$worker_id);
			//tcp的异步client连接tcp的server,只能是在worker进程中
			self::isWorkerProcess($worker_id) && self::registerRpc();
			
		});

		$this->webserver->on('open', function(websocket_server $server, $request) {
			try{

			}catch(\Exception $e) {
				// 捕捉异常
				\Swoolefy\Core\SwoolefyException::appException($e);
			}
		});

		$this->webserver->on('message', function(websocket_server $server, $frame) {
			try{
				$data = [$frame->fd, $frame->data];
				$server->task($data);
			}catch(\Exception $e) {
				// 捕捉异常
				\Swoolefy\Core\SwoolefyException::appException($e);
			}
		});


		//处理异步任务
		$this->webserver->on('task', function(websocket_server $server, $task_id, $from_worker_id, $data) {
			try{
				// list($fd,$data) = $data;
		    	//返回任务执行的结果
		    	return $data;
			}catch(\Exception $e) {
				// 捕捉异常
				\Swoolefy\Core\SwoolefyException::appException($e);
			}
		    
		});


		$this->webserver->on('finish', function(websocket_server $server, $task_id, $data) {
			try{
				if($this->tcp_client->isConnected()) {
					$this->tcp_client->send(swoole_pack($data).self::$config['tcp_setting']['package_eof']);
					while($text = $this->channel->pop()) {
						$this->tcp_client->send(swoole_pack($text).self::$config['tcp_setting']['package_eof']);
					}
				}else {
					self::registerRpc();
					$this->channel->push($data);
				}
			}catch(\Exception $e) {
				// 捕捉异常
				\Swoolefy\Core\SwoolefyException::appException($e);
			}
    		
		});

		//监听数据接收事件
		$this->tcpserver->on('receive', function(tcp_server $server, $fd, $from_worker_id, $data) {
			list($websocket_fd, $mydata) = swoole_unpack($data);
			$this->webserver->push($websocket_fd, $mydata);
		});


		$this->webserver->on('close', function(websocket_server $server, $fd) {
			try{

			}catch(\Exception $e) {
				// 捕捉异常
				\Swoolefy\Core\SwoolefyException::appException($e);
			}
		});

		/**
		 * 接受http请求
		 */
		if(!isset(self::$config['accept_http']) || self::$config['accept_http'] || self::$config['accept_http'] == 'true') {
			$this->webserver->on('request',function(Request $request, Response $response) {
				try{
					// google浏览器会自动发一次请求/favicon.ico,在这里过滤掉
					if($request->server['path_info'] == '/favicon.ico' || $request->server['request_uri'] == '/favicon.ico') {
	            		return $response->end();
	       			}
					swoole_unpack(self::$App)->run($request, $response);

				}catch(\Exception $e) {
					// 捕捉异常
					\Swoolefy\Core\SwoolefyException::appException($e);
				}
			});
		}

		/**
		 * 停止worker进程
		 */
		$this->webserver->on('WorkerStop',function(websocket_server $server, $worker_id) {
			// worker停止时的回调处理
			self::$startCtrl::workerStop($server, $worker_id);
		});

		/**
		 * worker进程异常错误回调函数
		 */
		$this->webserver->on('WorkerError',function(websocket_server $server, $worker_id, $worker_pid, $exit_code, $signal) {
			// worker停止的触发函数
			self::$startCtrl::workerError($server, $worker_id, $worker_pid, $exit_code, $signal);
		});

		/**
		 * worker进程退出回调函数，1.9.17+版本
		 */
		if(static::compareSwooleVersion()) {
			$this->webserver->on('WorkerExit',function(websocket_server $server, $worker_id) {
				// worker退出的触发函数
				self::$startCtrl::workerExit($server, $worker_id);
			});
		}

		$this->webserver->start();
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
		$this->tcp_client->connect('127.0.0.1', 9999, 0.5);
	}

}

if(isset($argv) && $argv[0] == basename(__FILE__)) {
	$websock = new WebsocketServer();
	$websock->start();
}
