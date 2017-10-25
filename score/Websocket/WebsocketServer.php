<?php
namespace Swoolefy\Websocket;

use Swoole\WebSocket\Server as websocket_server;
use Swoolefy\Core\BaseServer;
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
		'max_request' => 10000,
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
	 * $startctrl
	 * @var null
	 */
	public $startctrl = null;


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
		self::$setting = array_merge(self::$setting, self::$config['setting']);
		$this->webserver->set(self::$setting);
		parent::__construct();
		// 初始化启动类
		$startClass = isset(self::$config['start_init']) ? self::$config['start_init'] : 'Swoolefy\\Websocket\\StartInit';
		$this->startctrl = new $startClass();

	}

	public function start() {
		/**
		 * start回调
		 */
		$this->webserver->on('Start',function(websocket_server $server) {
			// 重新设置进程名称
			self::setMasterProcessName(self::$config['master_process_name']);
			// 启动的初始化函数
			$this->startctrl->start($server);
		});
		/**
		 * managerstart回调
		 */
		$this->webserver->on('ManagerStart',function(websocket_server $server) {
			// 重新设置进程名称
			self::setManagerProcessName(self::$config['manager_process_name']);
			// 启动的初始化函数
			$this->startctrl->managerStart($server);
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
			// 初始化整个应用对象
			is_null(self::$App) && self::$App = swoole_pack(self::$config['application_index']::getInstance($config=[]));
			// 超全局变量server
       		is_null(Swfy::$server) && Swfy::$server = $this->webserver;
       		is_null(Swfy::$config) && Swfy::$config = self::$config;
			// 启动的初始化函数
			$this->startctrl->workerStart($server,$worker_id);
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
		});

		$this->webserver->on('open', function (websocket_server $server, $request) {

		});

		$this->webserver->on('message', function (websocket_server $server, $frame) {
			$server->push($frame->fd,'hello welcome to websocket!');
		});

		$this->webserver->on('close', function (websocket_server $server, $fd) {

		});

		$this->webserver->start();
	}

}

if(isset($argv) && $argv[0] == basename(__FILE__)) {
	$websock = new WebsocketServer();
	$websock->start();
}
