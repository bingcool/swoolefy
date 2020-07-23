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

namespace Swoolefy\Tcp;

use Swoolefy\Core\Swfy;
use Swoolefy\Rpc\Pack;
use Swoolefy\Rpc\Text;
use Swoolefy\Core\BaseServer;

abstract class TcpServer extends BaseServer {

    /**
     * $serverName server服务名称
     * @var string
     */
    const SERVER_NAME = SWOOLEFY_TCP;

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
	 * $tcpServer
	 * @var \Swoole\Server
	 */
	public $tcpServer = null;

	/**
	 * $pack 封解包对象
	 * @var \Swoolefy\Rpc\Pack
	 */
	protected $Pack = null;

	/**
	 * $Text text协议对象
	 * @var \Swoolefy\Rpc\Text
	 */
	protected $Text = null;

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
		self::$server = $this->tcpServer = new \Swoole\Server(self::$config['host'], self::$config['port'], self::$swoole_process_mode, self::$swoole_socket_type);
		$this->tcpServer->set(self::$setting);
		parent::__construct();
		self::buildPackHandler();
		
	}

	public function start() {
		/**
		 * start回调
		 */
		$this->tcpServer->on('Start', function(\Swoole\Server $server) {
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
		$this->tcpServer->on('ManagerStart', function(\Swoole\Server $server) {
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
        $this->tcpServer->on('ManagerStop', function(\Swoole\Server $server) {
            try {
                $this->startCtrl->managerStop($server);
            }catch (\Throwable $e) {
                self::catchException($e);
            }
        });

		/**
		 * 启动worker进程监听回调，设置定时器
		 */
		$this->tcpServer->on('WorkerStart', function(\Swoole\Server $server, $worker_id) {
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
            Swfy::setSwooleServer($this->tcpServer);
            // 全局配置
            Swfy::setConf(self::$config);
			// 启动的初始化函数
			$this->startCtrl->workerStart($server, $worker_id);
			// 延迟绑定
			static::onWorkerStart($server, $worker_id);

		});

		/**
		 * tcp连接
		 */
		$this->tcpServer->on('connect', function(\Swoole\Server $server, $fd) {
    		try{
    			static::onConnect($server, $fd);
    		}catch(\Throwable $e) {
    			self::catchException($e);
    		}
		});

		/**
		 * 监听数据接收事件
		 */
		$this->tcpServer->on('receive', function(\Swoole\Server $server, $fd, $reactor_id, $data) {
			try{
				parent::beforeHandler();
				if(parent::isPackLength()) {
					$recv = $this->Pack->depack($server, $fd, $reactor_id, $data);
				}else {
					$recv = $this->Text->depackeof($data);
				}
				if($recv) {
					static::onReceive($server, $fd, $reactor_id, $recv);
				}
				return true;
    		}catch(\Throwable $e) {
    			self::catchException($e);
    		}
			
		});

		/**
		 * 处理异步任务
		 */
        if(parent::isTaskEnableCoroutine()) {
            $this->tcpServer->on('task', function(\Swoole\Server $server, \Swoole\Server\Task $task) {
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
            $this->tcpServer->on('task', function(\Swoole\Server $server, $task_id, $from_worker_id, $data) {
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
		$this->tcpServer->on('finish', function(\Swoole\Server $server, $task_id, $data) {
			try{
				static::onFinish($server, $task_id, $data);
			}catch(\Throwable $e) {
				self::catchException($e);
			}

		});

		/**
		 * 处理pipeMessage
		 */
		$this->tcpServer->on('pipeMessage', function(\Swoole\Server $server, $from_worker_id, $message) {
			try {
				static::onPipeMessage($server, $from_worker_id, $message);
				return true;
			}catch(\Throwable $e) {
				self::catchException($e);
			}
			
		});

		/**
		 * 关闭连接
		 */
		$this->tcpServer->on('close', function(\Swoole\Server $server, $fd, $reactorId) {
			try{
				// 销毁不完整数据
				if(parent::isPackLength()) {
					$this->Pack->destroy($server, $fd);
				}
				static::onClose($server, $fd);
			}catch(\Throwable $e) {
				self::catchException($e);
			}
		});

		/**
		 * 停止worker进程
		 */
		$this->tcpServer->on('WorkerStop', function(\Swoole\Server $server, $worker_id) {
			try{
				$this->startCtrl->workerStop($server, $worker_id);
			}catch(\Throwable $e) {
				self::catchException($e);
			}
		});

		/**
		 * worker进程异常错误回调函数
		 */
		$this->tcpServer->on('WorkerError', function(\Swoole\Server $server, $worker_id, $worker_pid, $exit_code, $signal) {
			try{
				$this->startCtrl->workerError($server, $worker_id, $worker_pid, $exit_code, $signal);
			}catch(\Throwable $e) {
				self::catchException($e);
			}
		});

		/**
		 * worker进程退出回调函数
		 */
        $this->tcpServer->on('WorkerExit', function(\Swoole\Server $server, $worker_id) {
            try{
                $this->startCtrl->workerExit($server, $worker_id);
            }catch(\Throwable $e) {
                self::catchException($e);
            }

        });

		$this->tcpServer->start();
	}

	/**
	 * buildPackHandler 创建pack处理对象
	 * @return void
	 */
	protected function buildPackHandler() {
		if(self::isPackLength()) {
			$this->Pack = new Pack(self::$server);
			// packet_length_check
			$this->Pack->setHeaderStruct(self::$config['packet']['server']['pack_header_struct']);
			$this->Pack->setPackLengthKey(self::$config['packet']['server']['pack_length_key']);
			if(isset(self::$config['packet']['server']['serialize_type'])) {
				$this->Pack->setSerializeType(self::$config['packet']['server']['serialize_type']);
			}
			$this->Pack->setHeaderLength(self::$setting['package_body_offset']);
			if(isset(self::$setting['package_max_length'])) {
				$package_max_length = (int)self::$setting['package_max_length'];
				$this->Pack->setPacketMaxlen($package_max_length);
			}
		}else {
			$this->Text = new Text();
			// packet_eof_check
			$this->Text->setPackEof(self::$setting['package_eof']);
			if(isset(self::$config['packet']['server']['serialize_type'])) {
				$serialize_type = self::$config['packet']['server']['serialize_type'];
			}else {
				$serialize_type = Text::DECODE_JSON;
			}
			$this->Text->setSerializeType($serialize_type);
		}
	}

    /**
     * isClientPackEof 根据设置判断客户端的分包方式
     * @return boolean
     * @throws \Exception
     */
	public static function isClientPackEof() {
		if(!isset(self::$config['packet']['client']['pack_check_type'])) {
            throw new \Exception("you must set ['packet']['client']  in the config file", 1);
        }
        if(in_array(self::$config['packet']['client']['pack_check_type'], ['eof', 'EOF']) ) {
            return true;
        }
        return false;
	}

    /**
     * isClientPackLength 根据设置判断客户端的分包方式
     * @return boolean
     * @throws \Exception
     */
	public static function isClientPackLength() {
		if(static::isClientPackEof()) {
			return false;
		}
		return true;
	}

    /**
     * pack  根据配置设置，按照客户端的接受数据方式，打包数据发回给客户端
     * @param mixed $data
     * @return mixed
     * @throws \Exception
     */
	public static function pack($data) {
		if(static::isClientPackLength()) {
            list($body_data, $header) = $data;
            $header_struct = self::$config['packet']['client']['pack_header_struct'];
            $pack_length_key = self::$config['packet']['client']['pack_length_key'];
            $serialize_type = self::$config['packet']['client']['serialize_type'];
            $header[$pack_length_key] = '';
            $pack_data = Pack::enpack($body_data, $header, $header_struct, $pack_length_key, $serialize_type);
		}else {
            $eof = self::$config['packet']['client']['pack_eof'];
            $serialize_type = self::$config['packet']['client']['serialize_type'];
            if($eof) {
                $pack_data = Text::enpackeof($data, $serialize_type, $eof);
            }else {
                $pack_data = Text::enpackeof($data, $serialize_type);
            }
		}

        return $pack_data;
	}
}

