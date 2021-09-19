<?php
/**
+----------------------------------------------------------------------
| swoolefy framework bases on swoole extension development, we can use it easily!
+----------------------------------------------------------------------
| Licensed ( https://opensource.org/licenses/MIT )
+----------------------------------------------------------------------
| @see https://github.com/bingcool/swoolefy
+----------------------------------------------------------------------
*/

namespace Swoolefy\Udp;

use Swoole\Server;
use Swoolefy\Core\EventApp;
use Swoolefy\Core\Swfy;
use Swoolefy\Core\BaseServer;
use Swoolefy\Core\EventController;

abstract class UdpServer extends BaseServer {

    /**
     * $serverName server服务名称
     * @var string
     */
    const SERVER_NAME = SWOOLEFY_UDP;

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
        'hook_flags' => SWOOLE_HOOK_ALL | SWOOLE_HOOK_CURL,
		'log_file' => __DIR__.'/log/log.txt',
		'pid_file' => __DIR__.'/log/server.pid',
	];

	/**
	 * $udpServer
	 * @var Server
	 */
	protected $udpServer = null;

    /**
     * __construct
     * @param array $config
     * @throws \Exception
     */
	public function __construct(array $config = []) {
		self::clearCache();
		self::$config = $config;
		self::$config['setting'] = self::$setting = array_merge(self::$setting, self::$config['setting']);
		self::setSwooleSockType();
        self::setServerName(self::SERVER_NAME);
		self::$swoole_socket_type = SWOOLE_SOCK_UDP;
		self::$server = $this->udpServer = new Server(self::$config['host'], self::$config['port'], self::$swoole_process_model, SWOOLE_SOCK_UDP);
		$this->udpServer->set(self::$setting);
		parent::__construct();
	}

	public function start() {
		/**
		 * start
		 */
		$this->udpServer->on('Start', function(Server $server) {
		    try{
                self::setMasterProcessName(self::$config['master_process_name']);
                $this->startCtrl->start($server);
            }catch (\Throwable $e) {
                self::catchException($e);
            }
		});

		/**
		 * managerStart
		 */
		$this->udpServer->on('ManagerStart', function(Server $server) {
            try{
                self::setManagerProcessName(self::$config['manager_process_name']);
                $this->startCtrl->managerStart($server);
            }catch (\Throwable $e) {
                self::catchException($e);
            }
		});

        /**
         * managerStop
         */
        $this->udpServer->on('ManagerStop', function(Server $server) {
            try{
                $this->startCtrl->managerStop($server);
            }catch (\Throwable $e) {
                self::catchException($e);
            }
        });

		/**
		 * WorkerStart
		 */
		$this->udpServer->on('WorkerStart', function(Server $server, $worker_id) {
			// 记录主进程加载的公共files,worker重启不会在加载的
			self::getIncludeFiles($worker_id);
            // 启动动态运行时的Coroutine
            self::runtimeEnableCoroutine();
			// registerShutdown
            self::registerShutdownFunction();
			// 重启worker时，清空字节cache
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
            Swfy::setSwooleServer($this->udpServer);
            // 全局配置
            Swfy::setConf(self::$config);
			// 启动的初始化函数
            (new EventApp())->registerApp(function(EventController $event) use($server, $worker_id) {
                $this->startCtrl->workerStart($server, $worker_id);
                static::onWorkerStart($server, $worker_id);
            });

		});

		/**
         * Packet
         */
		$this->udpServer->on('Packet', function(Server $server, $data, $clientInfo) {
			try{
				parent::beforeHandle();
				static::onPack($server, $data, $clientInfo);
				return true;
    		}catch(\Throwable $e) {
    			self::catchException($e);
    		}
		});

		/**
		 * task
		 */
        if(parent::isTaskEnableCoroutine()) {
            $this->udpServer->on('task', function(Server $server, \Swoole\Server\Task $task) {
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
            $this->udpServer->on('task', function(Server $server, $task_id, $from_worker_id, $data) {
                try{
                    $task_data = unserialize($data);
                    static::onTask($server, $task_id, $from_worker_id, $task_data);
                }catch(\Throwable $e) {
                    self::catchException($e);
                }
            });
        }

		/**
		 * finish
		 */
		$this->udpServer->on('finish', function(Server $server, $task_id, $data) {
			try{
                (new EventApp())->registerApp(function($event) use($server, $task_id, $data) {
                    static::onFinish($server, $task_id, $data);
                });
			}catch(\Throwable $e) {
				self::catchException($e);
			}
		});

		/**
		 * pipeMessage
		 */
		$this->udpServer->on('pipeMessage', function(Server $server, $from_worker_id, $message) {
			try {
                (new EventApp())->registerApp(function(EventController $event) use($server, $from_worker_id, $message) {
                    static::onPipeMessage($server, $from_worker_id, $message);
                });
				return true;
			}catch(\Throwable $e) {
				self::catchException($e);
			}
		});

		/**
		 * WorkerStop
		 */
		$this->udpServer->on('WorkerStop', function(Server $server, $worker_id) {
			try{
                (new EventApp())->registerApp(function(EventController $event) use($server, $worker_id) {
                    $this->startCtrl->workerStop($server, $worker_id);
                });
			}catch(\Throwable $e) {
				self::catchException($e);
			}
		});

		/**
		 * WorkerExit
		 */
        $this->udpServer->on('WorkerExit', function(Server $server, $worker_id) {
            \Swoole\Coroutine::create(function () use($server, $worker_id) {
                try{
                    (new EventApp())->registerApp(function(EventController $event) use($server, $worker_id) {
                        $this->startCtrl->workerExit($server, $worker_id);
                    });
                }catch(\Throwable $e) {
                    self::catchException($e);
                }
            });
        });

        /**
         * WorkerError
         * 注意，此回调是在manager进程中发生的，不能使用创建协程和使用协程api,否则报错
         */
        $this->udpServer->on('WorkerError', function(Server $server, $worker_id, $worker_pid, $exit_code, $signal) {
            try{
                $this->startCtrl->workerError($server, $worker_id, $worker_pid, $exit_code, $signal);
            }catch(\Throwable $e) {
                self::catchException($e);
            }
        });

		$this->udpServer->start();
	}
}