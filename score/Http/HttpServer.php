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

abstract class HttpServer extends BaseServer {

    /**
     * $serverName server服务名称
     * @var string
     */
    const SERVER_NAME = SWOOLEFY_HTTP;

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
		'log_file' => __DIR__.'/log/log.txt',
		'pid_file' => __DIR__.'/log/server.pid',
	];

	/**
	 * $webServer
	 * @var \Swoole\Http\Server
	 */
	protected $webServer = null;

    /**
     * __construct
     * @param array $config
     * @throws \Exception
     */
	public function __construct(array $config=[]) {
		self::clearCache();
		self::$config = array_merge(
			include(__DIR__.'/config.php'),
			$config
		);
		self::$config['setting'] = self::$setting = array_merge(self::$setting, self::$config['setting']);
		self::setSwooleSockType();
		self::setServerName(self::SERVER_NAME);
		self::$server = $this->webServer = new \Swoole\Http\Server(self::$config['host'], self::$config['port'], self::$swoole_process_mode, self::$swoole_socket_type);
		$this->webServer->set(self::$setting);
		parent::__construct();
	}

	public function start() {
		/**
		 * start回调
		 */
		$this->webServer->on('Start', function(\Swoole\Http\Server $server) {
            try{
                self::setMasterProcessName(self::$config['master_process_name']);
                $this->startCtrl->start($server);
            }catch (\Throwable $e) {
                self::catchException($e);
            }

		});

		/**
		 * managerStart回调
		 */
		$this->webServer->on('ManagerStart', function(\Swoole\Http\Server $server) {
		    try{
                self::setManagerProcessName(self::$config['manager_process_name']);
                $this->startCtrl->managerStart($server);
            }catch (\Throwable $e) {
                self::catchException($e);
            }
		});

        /**
         * managerStop回调
         */
        $this->webServer->on('ManagerStop', function(\Swoole\Http\Server $server) {
            try{
                $this->startCtrl->managerStop($server);
            }catch (\Throwable $e) {
                self::catchException($e);
            }
        });

		/**
		 * 启动worker进程监听回调，设置定时器
		 */
		$this->webServer->on('WorkerStart', function(\Swoole\Http\Server $server, $worker_id) {
			// 记录主进程加载的公共files,worker重启不会在加载的
			self::getIncludeFiles($worker_id);
			// registerShutdown
            self::registerShutdownFunction();
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
			// 启动动态运行时的Coroutine
			self::runtimeEnableCoroutine();
			// 超全局变量server
       		Swfy::setSwooleServer($this->webServer);
       		// 全局配置
       		Swfy::setConf(self::$config);
       		// 启动的初始化函数
			$this->startCtrl->workerStart($server, $worker_id);
			// 延迟绑定
			static::onWorkerStart($server, $worker_id);
			
		});

		/**
		 * worker进程停止回调函数
		 */
		$this->webServer->on('WorkerStop', function(\Swoole\Http\Server $server, $worker_id) {
			$this->startCtrl->workerStop($server,$worker_id);
		});

		/**
		 * 接受http请求
		 */
		$this->webServer->on('request', function(Request $request, Response $response) {
			try{
				parent::beforeHandler();
				static::onRequest($request, $response);
				return true;
			}catch(\Throwable $e) {
				self::catchException($e);
			}
		});

		/**
		 * 异步任务
		 */
        if(parent::isTaskEnableCoroutine()) {
            $this->webServer->on('task', function(\Swoole\Http\Server $server, \Swoole\Server\Task $task) {
                try{
                    $from_worker_id = $task->worker_id;
                    $task_id = $task->id;
                    $data = $task->data;
                    $task_data = unserialize($data);
                    static::onTask($server, $task_id, $from_worker_id, $task_data, $task);
                }catch(\Throwable $e) {
                    self::catchException($e);
                }
            });
        }else {
            $this->webServer->on('task', function(\Swoole\Http\Server $server, $task_id, $from_worker_id, $data) {
                try{
                    $task_data = unserialize($data);
                    static::onTask($server, $task_id, $from_worker_id, $task_data);
                }catch(\Throwable $e) {
                    self::catchException($e);
                }

            });
        }

		/**
		 * 处理异步任务
		 */
		$this->webServer->on('finish', function(\Swoole\Http\Server $server, $task_id, $data) {
			try {
				static::onFinish($server, $task_id, $data);
				return true;
			}catch(\Throwable $e) {
				self::catchException($e);
			}
			
		});

		/**
		 * 处理pipeMessage
		 */
		$this->webServer->on('pipeMessage', function(\Swoole\Http\Server $server, $src_worker_id, $message) {
			try {
				static::onPipeMessage($server, $src_worker_id, $message);
				return true;
			}catch(\Throwable $e) {
				self::catchException($e);
			}
		});

		/**
		 * worker进程异常错误回调函数
		 */
		$this->webServer->on('WorkerError', function(\Swoole\Http\Server $server, $worker_id, $worker_pid, $exit_code, $signal) {
			try{
				$this->startCtrl->workerError($server, $worker_id, $worker_pid, $exit_code, $signal);
			}catch(\Throwable $e) {
				self::catchException($e);
			}
		});

		/**
		 * worker进程退出回调函数
		 */
        $this->webServer->on('WorkerExit', function(\Swoole\Http\Server $server, $worker_id) {
            try{
                $this->startCtrl->workerExit($server, $worker_id);
            }catch(\Throwable $e) {
                self::catchException($e);
            }
        });

		$this->webServer->start();
	}

}
