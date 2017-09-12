<?php
namespace Swoolefy\Websocket;

include_once '../../vendor/autoload.php';

use Swoole\WebSocket\Server as websocket_server;
use Swoolefy\Core\BaseServer;
use Swoolefy\Core\Swfy;
use Swoole\Http\Request;
use Swoole\Http\Response;

class WebsocketServer extends BaseServer {

	/**
	 * $config
	 * @var null
	 */
	public static $config = null;

	/**
	 * $conf
	 * @var array
	 */
	public static $setting = [
		'reactor_num' => 1, //reactor thread num
		'worker_num' => 2,    //worker process num
		'max_request' => 10000,
		'daemonize' => 0,
		'log_file' => __DIR__.'/log.txt',
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
			// 初始化整个应用对象
			self::$App = swoole_pack(self::$config['application_index']::getInstance($config=[]));
	       	// 启动时提前加载文件
			self::startInclude();
			// 超全局变量server
	       	Swfy::$server = $this->webserver;
	       	Swfy::$config = self::$config;
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
					var_dump('error');
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

$websock = new WebsocketServer();

$websock->start();