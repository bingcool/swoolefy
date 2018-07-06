<?php
/**
+----------------------------------------------------------------------
| swoolefy framework bases on swoole extension development, we can use it easily!
+----------------------------------------------------------------------
| Licensed ( https://opensource.org/licenses/MIT )
+----------------------------------------------------------------------
| Author: bingcool <bingcoolhuang@gmail.com || 2437667702@qq.com>
+----------------------------------------------------------------------
*/

namespace Swoolefy\Http;

use Swoolefy\Core\Swfy;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoolefy\Core\BaseServer;
use Swoolefy\Core\Application;
use Swoole\Http\Server as http_server;

abstract class HttpServer extends BaseServer {
	/**
	 * $setting
	 * @var array
	 */
	public static $setting = [
		'reactor_num' => 1,
		'worker_num' => 1,
		'max_request' => 1000,
		'task_worker_num' => 1,
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
	 * $startctrl
	 * @var null
	 */
	public $startCtrl = null;

	/**
	 * $serverName server服务名称
	 * @var string
	 */
	public static $serverName = SWOOLEFY_HTTP;

	/**
	 * __construct
	 * @param array $config
	 */
	public function __construct(array $config=[]) {
		// 刷新字节缓存
		self::clearCache();
		self::$config = array_merge(
			include(__DIR__.'/config.php'),
			$config
		);
		self::$config['setting'] = self::$setting = array_merge(self::$setting, self::$config['setting']);
		//设置进程模式和socket类型
		self::setSwooleSockType();
		self::$server = $this->webserver = new http_server(self::$config['host'], self::$config['port'], self::$swoole_process_mode, self::$swoole_socket_type);
		$this->webserver->set(self::$setting);
		parent::__construct();
		// 初始化启动类
		$startInitClass = isset(self::$config['start_init']) ? self::$config['start_init'] : 'Swoolefy\\Core\\StartInit';

		$this->startCtrl = new $startInitClass();
		$this->startCtrl->init(); 
		
	}

	public function start() {
		/**
		 * start回调
		 */
		$this->webserver->on('Start', function(http_server $server) {
			// 重新设置进程名称
			self::setMasterProcessName(self::$config['master_process_name']);
			// 启动的初始化函数
			$this->startCtrl->start($server);
		});
		/**
		 * managerstart回调
		 */
		$this->webserver->on('ManagerStart', function(http_server $server) {
			// 重新设置进程名称
			self::setManagerProcessName(self::$config['manager_process_name']);
			// 启动的初始化函数
			$this->startCtrl->managerStart($server);
		});

		/**
		 * 启动worker进程监听回调，设置定时器
		 */
		$this->webserver->on('WorkerStart', function(http_server $server, $worker_id) {
			// 记录主进程加载的公共files,worker重启不会在加载的
			self::getIncludeFiles(static::$serverName);
			// 重启worker时，刷新字节cache
			self::clearCache();
			// 重新设置进程名称
			self::setWorkerProcessName(self::$config['worker_process_name'], $worker_id, self::$setting['worker_num']);
			// 设置worker工作的进程组
			self::setWorkerUserGroup(self::$config['www_user']);
			// 启动时提前加载文件
			self::startInclude();
			// 记录worker的进程worker_pid与worker_id的映射
			self::setWorkersPid($worker_id, $server->worker_pid);
			// 超全局变量server
       		Swfy::$server = $this->webserver;
       		Swfy::$config = self::$config;
       		// 初始化整个应用对象
			is_null(self::$App) && self::$App = swoole_pack(self::$config['application_index']::getInstance($config=[]));
       		// 启动的初始化函数
			$this->startCtrl->workerStart($server, $worker_id);
			
		});

		/**
		 * worker进程停止回调函数
		 */
		$this->webserver->on('WorkerStop', function(http_server $server, $worker_id) {
			// worker停止的触发函数
			$this->startCtrl->workerStop($server,$worker_id);
			
		});

		/**
		 * 接受http请求
		 */
		$this->webserver->on('request', function(Request $request, Response $response) {
			try{
				static::onRequest($request, $response);
				return true;
			}catch(\Exception $e) {
				self::catchException($e);
			}
		});

		/**
		 * 异步任务
		 */
		$this->webserver->on('task', function(http_server $server, $task_id, $from_worker_id, $data) {
			try{
				$task_data = swoole_unpack($data);
				static::onTask($task_id, $from_worker_id, $task_data);
			}catch(\Exception $e) {
				self::catchException($e);
			}
			
		});

		/**
		 * 处理异步任务
		 */
		$this->webserver->on('finish', function(http_server $server, $task_id, $data) {
			try {
				static::onFinish($server, $task_id, $data);
				return true;
			}catch(\Exception $e) {
				self::catchException($e);
			}
			
		});

		/**
		 * 处理pipeMessage
		 */
		$this->webserver->on('pipeMessage', function(http_server $server, $src_worker_id, $message) {
			try {
				static::onPipeMessage($server, $src_worker_id, $message);
				return true;
			}catch(\Exception $e) {
				self::catchException($e);
			}
			
		});

		/**
		 * worker进程异常错误回调函数
		 */
		$this->webserver->on('WorkerError', function(http_server $server, $worker_id, $worker_pid, $exit_code, $signal) {
			try{
				// worker停止的触发函数
				$this->startCtrl->workerError($server, $worker_id, $worker_pid, $exit_code, $signal);
			}catch(\Exception $e) {
				self::catchException($e);
			}
			
		});

		/**
		 * worker进程退出回调函数，1.9.17+版本
		 */
		if(static::compareSwooleVersion()) {
			$this->webserver->on('WorkerExit', function(http_server $server, $worker_id) {
				try{
					// worker退出的触发函数
					$this->startCtrl->workerExit($server, $worker_id);
				}catch(\Exception $e) {
					self::catchException($e);
				}
				
			});
		}
		
		$this->webserver->start();
	}

}
