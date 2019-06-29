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

namespace Swoolefy\Tcp;

use Swoolefy\Core\Swfy;
use Swoolefy\Rpc\Pack;
use Swoolefy\Rpc\Text;
use Swoolefy\Core\BaseServer;

abstract class TcpServer extends BaseServer {
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
	public $tcpserver = null;

	/**
	 * $pack 封解包对象
	 * @var null
	 */
	protected $Pack = null;

	/**
	 * $Text text协议对象
	 * @var null
	 */
	protected $Text = null;

	/**
	 * $serverName server服务名称
	 * @var string
	 */
	const SERVER_NAME = SWOOLEFY_TCP;

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
        self::setServerName(self::SERVER_NAME);
		self::$server = $this->tcpserver = new \Swoole\Server(self::$config['host'], self::$config['port'], self::$swoole_process_mode, self::$swoole_socket_type);
		$this->tcpserver->set(self::$setting);
		parent::__construct();
		// 设置Pack包处理对象
		self::buildPackHander();
		
	}

	public function start() {
		/**
		 * start回调
		 */
		$this->tcpserver->on('Start', function(\Swoole\Server $server) {
			// 重新设置进程名称
			self::setMasterProcessName(self::$config['master_process_name']);
			// 启动的初始化函数
			$this->startCtrl->start($server);
		});

		/**
		 * managerstart回调
		 */
		$this->tcpserver->on('ManagerStart', function(\Swoole\Server $server) {
			// 重新设置进程名称
			self::setManagerProcessName(self::$config['manager_process_name']);
			// 启动的初始化函数
            try{
                $this->startCtrl->managerStart($server);
            }catch (\Exception $e) {
                self::catchException($e);
            }

		});

        /**
         * managerstop回调
         */
        $this->tcpserver->on('ManagerStop', function(\Swoole\Server $server) {
            try {
                $this->startCtrl->managerStop($server);
            }catch (\Exception $e) {
                self::catchException($e);
            }
        });

		/**
		 * 启动worker进程监听回调，设置定时器
		 */
		$this->tcpserver->on('WorkerStart', function(\Swoole\Server $server, $worker_id) {
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
       		Swfy::$server = $this->tcpserver;
       		Swfy::$config = self::$config;
			// 启动的初始化函数
			$this->startCtrl->workerStart($server, $worker_id);
			// 延迟绑定
			static::onWorkerStart($server, $worker_id);

		});

		/**
		 * tcp连接
		 */
		$this->tcpserver->on('connect', function(\Swoole\Server $server, $fd) {
    		try{
    			static::onConnet($server, $fd);
    		}catch(\Exception $e) {
    			self::catchException($e);
    		}
		});

		/**
		 * 监听数据接收事件
		 */
		$this->tcpserver->on('receive', function(\Swoole\Server $server, $fd, $reactor_id, $data) {
			try{
				parent::beforeHandler();
				// 服务端为length检查包
				if(parent::isPackLength()) {
					$recv = $this->Pack->depack($server, $fd, $reactor_id, $data);
				}else {
					// 服务端为eof检查包
					$recv = $this->Text->depackeof($data);
				}
				if($recv) {
					//延迟绑定，服务处理实例
					static::onReceive($server, $fd, $reactor_id, $recv);
				}
				return true;
    		}catch(\Exception $e) {
    			self::catchException($e);
    		}
			
		});

		/**
		 * 处理异步任务
		 */
        if(parent::isTaskEnableCoroutine()) {
            $this->tcpserver->on('task', function(\Swoole\Server $server, \Swoole\Server\Task $task) {
                try{
                    $from_worker_id = $task->worker_id;
                    $task_id = $task->id;
                    $data = $task->data;
                    $task_data = unserialize($data);
                    static::onTask($server, $task_id, $from_worker_id, $task_data, $task);
                }catch(\Exception $e) {
                    self::catchException($e);
                }
            });

        }else {
            $this->tcpserver->on('task', function(\Swoole\Server $server, $task_id, $from_worker_id, $data) {
                try{
                    $task_data = unserialize($data);
                    static::onTask($server, $task_id, $from_worker_id, $task_data);
                }catch(\Exception $e) {
                    self::catchException($e);
                }

            });
        }

		/**
		 * 异步任务完成
		 */
		$this->tcpserver->on('finish', function(\Swoole\Server $server, $task_id, $data) {
			try{
				static::onFinish($server, $task_id, $data);
			}catch(\Exception $e) {
				self::catchException($e);
			}

		});

		/**
		 * 处理pipeMessage
		 */
		$this->tcpserver->on('pipeMessage', function(\Swoole\Server $server, $from_worker_id, $message) {
			try {
				static::onPipeMessage($server, $from_worker_id, $message);
				return true;
			}catch(\Exception $e) {
				self::catchException($e);
			}
			
		});

		/**
		 * 关闭连接
		 */
		$this->tcpserver->on('close', function(\Swoole\Server $server, $fd, $reactorId) {
			try{
				// 销毁不完整数据
				if(parent::isPackLength()) {
					$this->Pack->destroy($server, $fd);
				}
				// 延迟绑定
				static::onClose($server, $fd);
			}catch(\Exception $e) {
				self::catchException($e);
			}
		});

		/**
		 * 停止worker进程
		 */
		$this->tcpserver->on('WorkerStop', function(\Swoole\Server $server, $worker_id) {
			try{
				$this->startCtrl->workerStop($server, $worker_id);
			}catch(\Exception $e) {
				self::catchException($e);
			}
		});

		/**
		 * worker进程异常错误回调函数
		 */
		$this->tcpserver->on('WorkerError', function(\Swoole\Server $server, $worker_id, $worker_pid, $exit_code, $signal) {
			try{
				$this->startCtrl->workerError($server, $worker_id, $worker_pid, $exit_code, $signal);
			}catch(\Exception $e) {
				self::catchException($e);
			}
		});

		/**
		 * worker进程退出回调函数，1.9.17+版本
		 */
        $this->tcpserver->on('WorkerExit', function(\Swoole\Server $server, $worker_id) {
            try{
                $this->startCtrl->workerExit($server, $worker_id);
            }catch(\Exception $e) {
                self::catchException($e);
            }

        });

		$this->tcpserver->start();
	}

	/**
	 * buildPackHander 创建pack处理对象
	 * @return void
	 */
	public function buildPackHander() {
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
     * @throws
	 * @return boolean
	 */
	public static function isClientPackEof() {
		if(isset(self::$config['packet']['client']['pack_check_type'])) {
			if(self::$config['packet']['client']['pack_check_type'] == 'eof') {
				//$client_check是eof方式
				return true;
			}
			return false;
		}else {
			throw new \Exception("you must set ['packet']['client']  in the config file", 1);	
		}
		
	}

	/**
	 * isClientPackLength 根据设置判断客户端的分包方式
	 * @return boolean
	 */
	public static function isClientPackLength() {
		if(static::isClientPackEof()) {
			return false;
		}
		return true;
	}

	/**
	 * pack  根据配置设置，按照客户端的接受数据方式，打包数据发回给客户端
	 * @param    mixed   $data
	 * @return   mixed
	 */
	public static function pack($data) {
		if(static::isClientPackLength()) {
            // 客户端是length方式分包
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

