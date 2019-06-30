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

namespace Swoolefy\Udp;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\BaseServer;
use Swoole\Server as udp_server;

abstract class UdpServer extends BaseServer {
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
	 * $tcpserver 
	 * @var null
	 */
	public $udpserver = null;

	/**
	 * $serverName server服务名称
	 * @var string
	 */
	const SERVER_NAME = SWOOLEFY_UDP;

	/**
	 * __construct
	 * @param array $config
	 */
	public function __construct(array $config=[]) {
		self::clearCache();
		self::$config = $config;
		self::$config['setting'] = self::$setting = array_merge(self::$setting, self::$config['setting']);
		self::setSwooleSockType();
        self::setServerName(self::SERVER_NAME);
		// UDP服务器,固定为SWOOLE_SOCK_UDP
		self::$swoole_socket_type = SWOOLE_SOCK_UDP;
		self::$server = $this->udpserver = new \Swoole\Server(self::$config['host'], self::$config['port'], self::$swoole_process_mode, SWOOLE_SOCK_UDP);
		$this->udpserver->set(self::$setting);
		parent::__construct();
	}

	public function start() {
		/**
		 * start回调
		 */
		$this->udpserver->on('Start', function(\Swoole\Server $server) {
			// 重新设置进程名称
			self::setMasterProcessName(self::$config['master_process_name']);
			// 启动的初始化函数
			$this->startCtrl->start($server);
		});

		/**
		 * managerstart回调
		 */
		$this->udpserver->on('ManagerStart', function(\Swoole\Server $server) {
			// 重新设置进程名称
			self::setManagerProcessName(self::$config['manager_process_name']);
			// 启动的初始化函数
            try{
                $this->startCtrl->managerStart($server);
            }catch (\Throwable $e) {
                self::catchException($e);
            }

		});

        /**
         * managerstop回调
         */
        $this->udpserver->on('ManagerStop', function(\Swoole\Server $server) {
            try{
                $this->startCtrl->managerStop($server);
            }catch (\Throwable $e) {
                self::catchException($e);
            }
        });

		/**
		 * 启动worker进程监听回调，设置定时器
		 */
		$this->udpserver->on('WorkerStart', function(\Swoole\Server $server, $worker_id) {
			// 记录主进程加载的公共files,worker重启不会在加载的
			self::getIncludeFiles($worker_id);
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
			// 启动动态运行时的Coroutine
			self::runtimeEnableCoroutine();
            // 超全局变量server
            Swfy::setSwooleServer($this->udpserver);
            // 全局配置
            Swfy::setConf(self::$config);
			// 启动的初始化函数
			$this->startCtrl->workerStart($server, $worker_id);
			// 延迟绑定
			static::onWorkerStart($server, $worker_id);

		});

		/**
         * 监听数据接收事件
         */
		$this->udpserver->on('Packet', function(\Swoole\Server $server, $data, $clientInfo) {
			try{
				parent::beforeHandler();
				static::onPack($server, $data, $clientInfo);
				return true;
    		}catch(\Throwable $e) {
    			self::catchException($e);
    		}
			
		});

		/**
		 * 处理异步任务
		 */
        if(parent::isTaskEnableCoroutine()) {
            $this->udpserver->on('task', function(\Swoole\Server $server, \Swoole\Server\Task $task) {
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
            $this->udpserver->on('task', function(\Swoole\Server $server, $task_id, $from_worker_id, $data) {
                try{
                    $task_data = unserialize($data);
                    static::onTask($server, $task_id, $from_worker_id, $task_data);
                }catch(\Throwable $e) {
                    self::catchException($e);
                }

            });
        }

		/**
		 * 异步任务完成
		 */
		$this->udpserver->on('finish', function(\Swoole\Server $server, $task_id, $data) {
			try{
				static::onFinish($server, $task_id, $data);
			}catch(\Throwable $e) {
				self::catchException($e);
			}

		});

		/**
		 * 处理pipeMessage
		 */
		$this->udpserver->on('pipeMessage', function(\Swoole\Server $server, $from_worker_id, $message) {
			try {
				static::onPipeMessage($server, $from_worker_id, $message);
				return true;
			}catch(\Throwable $e) {
				self::catchException($e);
			}
			
		});

		/**
		 * 停止worker进程
		 */
		$this->udpserver->on('WorkerStop', function(\Swoole\Server $server, $worker_id) {
			try{
				$this->startCtrl->workerStop($server, $worker_id);
			}catch(\Throwable $e) {
				self::catchException($e);
			}
			
		});

		/**
		 * worker进程异常错误回调函数
		 */
		$this->udpserver->on('WorkerError', function(\Swoole\Server $server, $worker_id, $worker_pid, $exit_code, $signal) {
			try{
				$this->startCtrl->workerError($server, $worker_id, $worker_pid, $exit_code, $signal);
			}catch(\Throwable $e) {
				self::catchException($e);
			}
			
		});

		/**
		 * worker进程退出回调函数，1.9.17+版本
		 */
        $this->udpserver->on('WorkerExit', function(\Swoole\Server $server, $worker_id) {
            try{
                $this->startCtrl->workerExit($server, $worker_id);
            }catch(\Throwable $e) {
                self::catchException($e);
            }
        });

		$this->udpserver->start();
	}
}