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

namespace Swoolefy\Websocket;

use Swoolefy\Core\EventApp;
use Swoolefy\Core\Swfy;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoolefy\Core\BaseServer;

abstract class WebsocketServer extends BaseServer {
    /**
     * $serverName server服务名称
     * @var string
     */
    const SERVER_NAME = SWOOLEFY_WEBSOCKET;

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
	 * @var \Swoole\WebSocket\Server
	 */
	protected $webServer = null;

    /**
     * __construct
     * @param array $config
     * @throws \Exception
     */
	public function __construct(array $config=[]) {
		self::clearCache();
		self::$config = $config;
		self::$config['setting'] = self::$setting = array_merge(self::$setting, self::$config['setting']);
		self::setSwooleSockType();
        self::setServerName(self::SERVER_NAME);
		self::$server = $this->webServer = new \Swoole\WebSocket\Server(self::$config['host'], self::$config['port'], self::$swoole_process_mode, self::$swoole_socket_type);
		$this->webServer->set(self::$setting);
		parent::__construct();
	}

	public function start() {
		/**
		 * start回调
		 */
		$this->webServer->on('Start', function(\Swoole\WebSocket\Server $server) {
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
		$this->webServer->on('ManagerStart', function(\Swoole\WebSocket\Server $server) {
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
        $this->webServer->on('ManagerStop', function(\Swoole\WebSocket\Server $server) {
            try{
                $this->startCtrl->managerStop($server);
            }catch (\Throwable $e) {
                self::catchException($e);
            }
        });

		/**
		 * 启动worker进程监听回调，设置定时器
		 */
		$this->webServer->on('WorkerStart', function(\Swoole\WebSocket\Server $server, $worker_id) {
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
            Swfy::setSwooleServer($this->webServer);
            // 全局配置
            Swfy::setConf(self::$config);
			// 启动的初始化函数
            (new EventApp())->registerApp(function($event) use($server, $worker_id) {
                $this->startCtrl->workerStart($server, $worker_id);
                static::onWorkerStart($server, $worker_id);
            });
		});

		/**
		 * 自定义handshake,如果子类设置了onHandshake()，函数中必须要"自定义"握手过程,否则将不会建立websockdet连接
		 * @see https://wiki.swoole.com/wiki/page/409.html
		 */
		if(method_exists($this, 'onHandshake')) {
			$this->webServer->on('handshake', function(Request $request, Response $response) {
				try{
					// 自定义handshake函数
					static::onHandshake($request, $response);
				}catch(\Throwable $e) {
					self::catchException($e);
				}
			});
		} 
		
		/**
		 * open 函数处理
		 */
		$this->webServer->on('open', function(\Swoole\WebSocket\Server $server, $request) {
			try{
                (new EventApp())->registerApp(function() use($server, $request) {
                    static::onOpen($server, $request);
                });
				return true;
			}catch(\Throwable $e) {
				self::catchException($e);
			}
		});

		/**
		 * message 函数
		 */
		$this->webServer->on('message', function(\Swoole\WebSocket\Server $server, $frame) {
			try{
				parent::beforeHandle();
				static::onMessage($server, $frame);
				return true;
			}catch(\Throwable $e) {
				self::catchException($e);
			}
		});

		/**
		 * task 函数,处理异步任务
		 */
        if(parent::isTaskEnableCoroutine()) {
            $this->webServer->on('task', function(\Swoole\WebSocket\Server $server, \Swoole\Server\Task $task) {
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
            $this->webServer->on('task', function(\Swoole\WebSocket\Server $server, $task_id, $from_worker_id, $data) {
                try{
                    $task_data = unserialize($data);
                    static::onTask($server, $task_id, $from_worker_id, $task_data);
                }catch(\Throwable $e) {
                    self::catchException($e);
                }
            });
        }

		/**
		 * finish 函数,异步任务完成
		 */
		$this->webServer->on('finish', function(\Swoole\WebSocket\Server $server, $task_id, $data) {
			try{
                (new EventApp())->registerApp(function($event) use($server, $task_id, $data) {
                    static::onFinish($server, $task_id, $data);
                });
				return true;
			}catch(\Throwable $e) {
				self::catchException($e);
			}
    		
		});

		/**
		 * 处理pipeMessage
		 */
		$this->webServer->on('pipeMessage', function(\Swoole\WebSocket\Server $server, $from_worker_id, $message) {
			try {
                (new EventApp())->registerApp(function() use($server, $from_worker_id, $message) {
                    static::onPipeMessage($server, $from_worker_id, $message);
                });
				return true;
			}catch(\Throwable $e) {
				self::catchException($e);
			}
		});

		/**
		 * close 函数,关闭连接
		 */
		$this->webServer->on('close', function(\Swoole\WebSocket\Server $server, $fd, $reactorId) {
			try{
                (new EventApp())->registerApp(function($event) use($server, $fd) {
                    static::onClose($server, $fd);
                });
				return true;
			}catch(\Throwable $e) {
				self::catchException($e);
			}
		});

		/**
		 * 接受http请求
		 */
		if(isset(self::$config['accept_http'])){
            $accept_http = filter_var(self::$config['accept_http'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if($accept_http) {
                $this->webServer->on('request', function (Request $request, Response $response) {
                    try{
                        if($request->server['path_info'] == '/favicon.ico' || $request->server['request_uri'] == '/favicon.ico') {
                            return $response->end();
                        }
                        static::onRequest($request, $response);
                        return true;
                    } catch (\Throwable $e) {
                        self::catchException($e);
                    }
                });
            }
		}

		/**
		 * 停止worker进程
		 */
		$this->webServer->on('WorkerStop', function(\Swoole\WebSocket\Server $server, $worker_id) {
			try{
                (new EventApp())->registerApp(function($event) use($server, $worker_id) {
                    $this->startCtrl->workerStop($server, $worker_id);
                });
			}catch(\Throwable $e) {
				self::catchException($e);
			}
		});

		/**
		 * worker进程异常错误回调函数
		 */
		$this->webServer->on('WorkerError', function(\Swoole\WebSocket\Server $server, $worker_id, $worker_pid, $exit_code, $signal) {
			\Swoole\Coroutine::create(function () use($server, $worker_id, $worker_pid, $exit_code, $signal) {
                try{
                    (new EventApp())->registerApp(function($event) use($server, $worker_id, $worker_pid, $exit_code, $signal) {
                        $this->startCtrl->workerError($server, $worker_id, $worker_pid, $exit_code, $signal);
                    });
                }catch(\Throwable $e) {
                    self::catchException($e);
                }
            });

		});

		/**
		 * worker进程退出回调函数
		 */
        $this->webServer->on('WorkerExit', function(\Swoole\WebSocket\Server $server, $worker_id) {
            \Swoole\Coroutine::create(function () use($server, $worker_id) {
                try{
                    (new EventApp())->registerApp(function($event) use($server, $worker_id) {
                        $this->startCtrl->workerExit($server, $worker_id);
                    });
                }catch(\Throwable $e) {
                    self::catchException($e);
                }
            });
        });

		$this->webServer->start();
	}


}
