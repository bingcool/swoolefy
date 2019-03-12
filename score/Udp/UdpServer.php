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
	public static $serverName = SWOOLEFY_UDP;

	/**
	 * __construct
	 * @param array $config
	 */
	public function __construct(array $config=[]) {
		self::clearCache();
		self::$config = $config;
		self::$config['setting'] = self::$setting = array_merge(self::$setting, self::$config['setting']);
		//设置进程模式和socket类型
		self::setSwooleSockType();
		// UDP服务器,固定为SWOOLE_SOCK_UDP
		self::$swoole_socket_type = SWOOLE_SOCK_UDP;
		self::$server = $this->udpserver = new udp_server(self::$config['host'], self::$config['port'], self::$swoole_process_mode, SWOOLE_SOCK_UDP);
		$this->udpserver->set(self::$setting);
		parent::__construct();
		// 初始化启动类
        $this->startCtrl = parent::startHander();
        $this->startCtrl->init();
		
	}

	public function start() {
		/**
		 * start回调
		 */
		$this->udpserver->on('Start', function(udp_server $server) {
			// 重新设置进程名称
			self::setMasterProcessName(self::$config['master_process_name']);
			// 启动的初始化函数
			$this->startCtrl->start($server);
		});

		/**
		 * managerstart回调
		 */
		$this->udpserver->on('ManagerStart', function(udp_server $server) {
			// 重新设置进程名称
			self::setManagerProcessName(self::$config['manager_process_name']);
			// 启动的初始化函数
			$this->startCtrl->managerStart($server);
		});

		/**
		 * 启动worker进程监听回调，设置定时器
		 */
		$this->udpserver->on('WorkerStart', function(udp_server $server, $worker_id) {
			// 记录主进程加载的公共files,worker重启不会在加载的
			self::getIncludeFiles(static::$serverName);
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
			// 启动动态运行时的Coroutine
			self::runtimeEnableCoroutine();
			// 超全局变量server
       		Swfy::$server = $this->udpserver;
       		Swfy::$config = self::$config;
			// 启动的初始化函数
			$this->startCtrl->workerStart($server,$worker_id);
			// 延迟绑定
			static::onWorkerStart($server, $worker_id);

		});

		/**
         * 监听数据接收事件
         */
		$this->udpserver->on('Packet', function(udp_server $server, $data, $clientInfo) {
			try{
				parent::beforeHandler();
				// 延迟绑定，服务处理实例
				static::onPack($server, $data, $clientInfo);
				return true;
    		}catch(\Exception $e) {
    			self::catchException($e);
    		}
			
		});

		/**
		 * 处理异步任务
		 */
        if(parent::isTaskEnableCoroutine()) {
            $this->udpserver->on('task', function(udp_server $server, \Swoole\Server\Task $task) {
                try{
                    $from_worker_id = $task->worker_id;
                    //任务的编号
                    $task_id = $task->id;
                    //任务的数据
                    $data = $task->data;

                    $task_data = unserialize($data);
                    static::onTask($server, $task_id, $from_worker_id, $task_data, $task);
                }catch(\Exception $e) {
                    self::catchException($e);
                }
            });
        }else {
            $this->udpserver->on('task', function(udp_server $server, $task_id, $from_worker_id, $data) {
                try{
                    $task_data = unserialize($data);
                    // 延迟绑定
                    static::onTask($server, $task_id, $from_worker_id, $task_data);
                }catch(\Exception $e) {
                    self::catchException($e);
                }

            });
        }

		/**
		 * 异步任务完成
		 */
		$this->udpserver->on('finish', function(udp_server $server, $task_id, $data) {
			try{
				static::onFinish($server, $task_id, $data);
			}catch(\Exception $e) {
				self::catchException($e);
			}

		});

		/**
		 * 处理pipeMessage
		 */
		$this->udpserver->on('pipeMessage', function(udp_server $server, $from_worker_id, $message) {
			try {
				static::onPipeMessage($server, $from_worker_id, $message);
				return true;
			}catch(\Exception $e) {
				self::catchException($e);
			}
			
		});

		/**
		 * 停止worker进程
		 */
		$this->udpserver->on('WorkerStop', function(udp_server $server, $worker_id) {
			try{
				// worker停止时的回调处理
				$this->startCtrl->workerStop($server, $worker_id);
			}catch(\Exception $e) {
				self::catchException($e);
			}
			
		});

		/**
		 * worker进程异常错误回调函数
		 */
		$this->udpserver->on('WorkerError', function(udp_server $server, $worker_id, $worker_pid, $exit_code, $signal) {
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
        $this->udpserver->on('WorkerExit', function(udp_server $server, $worker_id) {
            try{
                // worker退出的触发函数
                $this->startCtrl->workerExit($server, $worker_id);
            }catch(\Exception $e) {
                self::catchException($e);
            }
        });

		$this->udpserver->start();
	}
}