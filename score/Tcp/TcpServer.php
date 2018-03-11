<?php
namespace Swoolefy\Tcp;

use Swoole\Server as tcp_server;
use Swoolefy\Core\BaseServer;
use Swoolefy\Core\Swfy;
use Swoolefy\Core\Pack;

abstract class TcpServer extends BaseServer {
	/**
	 * $setting
	 * @var array
	 */
	public static $setting = [
		'reactor_num' => 1, //reactor thread num
		'worker_num' => 1,    //worker process num
		'max_request' => 5,
		'task_worker_num' =>5,
		'task_tmpdir' => '/dev/shm',
		'daemonize' => 0,
	];

	/**
	 * $tcpserver 
	 * @var null
	 */
	public $tcpserver = null;

	/**
	 * $channel 进程共享内存信道队列
	 * @var null
	 */
	public $channel = null;

	/**
	 * $pack 封解包对象
	 * @var null
	 */
	public $pack = null;

	/**
	 * $startctrl
	 * @var null
	 */
	public static $startCtrl = null;

	/**
	 * $serverName 默认的server服务名称
	 * @var string
	 */
	public $serverName = 'Tcp';

	/**
	 * __construct
	 * @param array $config
	 */
	public function __construct(array $config=[]) {
		self::clearCache();
		self::$config = $config;
		self::$server = $this->tcpserver = new tcp_server(self::$config['host'], self::$config['port']);
		self::$config['setting'] = self::$setting = array_merge(self::$setting, self::$config['setting']);
		$this->tcpserver->set(self::$setting);
		parent::__construct();

		// 创建一个channel通道,worker进程共享内存
		$this->channel = new \Swoole\Channel(1024 * 256);

		// 初始化启动类
		self::$startCtrl = isset(self::$config['start_init']) ? self::$config['start_init'] : 'Swoolefy\\Tcp\\StartInit';
		
		/**
		 * 设置Pack包处理对象
		 */
		$this->pack = new Pack(self::$server);
		if(self::isPackLength()) {
			// packet_langth_check
			$this->pack->header_struct = self::$config['packet']['server']['pack_header_strct'];
			$this->pack->pack_length_key = self::$config['packet']['server']['pack_length_key'];
			$this->pack->serialize_type = Pack::DECODE_JSON;
			$this->pack->header_length = self::$setting['package_body_offset'];
			$this->pack->packet_maxlen = self::$setting['package_max_length'];
		}else {
			// packet_eof_check
			$this->pack->pack_eof = self::$setting['package_eof'];
			$this->pack->serialize_type = Pack::DECODE_JSON;
		}
		
	}

	public function start() {
		/**
		 * start回调
		 */
		$this->tcpserver->on('Start',function(tcp_server $server) {
			// 重新设置进程名称
			self::setMasterProcessName(self::$config['master_process_name']);
			// 启动的初始化函数
			self::$startCtrl::start($server);
		});
		/**
		 * managerstart回调
		 */
		$this->tcpserver->on('ManagerStart',function(tcp_server $server) {
			// 重新设置进程名称
			self::setManagerProcessName(self::$config['manager_process_name']);
			// 启动的初始化函数
			self::$startCtrl::managerStart($server);
		});

		/**
		 * 启动worker进程监听回调，设置定时器
		 */
		$this->tcpserver->on('WorkerStart',function(tcp_server $server, $worker_id) {
			// 记录主进程加载的公共files,worker重启不会在加载的
			self::getIncludeFiles($this->serverName);
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
			// 超全局变量server
       		Swfy::$server = $this->tcpserver;
       		Swfy::$config = self::$config;

       		// 单例服务处理实例
       		is_null(self::$service) && self::$service = swoole_pack(self::$config['application_index']::getInstance($config=[]));
			// 启动的初始化函数
			self::$startCtrl::workerStart($server,$worker_id);
			// 延迟绑定
			static::onWorkerStart($server, $worker_id);

		});

		// tcp连接
		$this->tcpserver->on('connect', function (tcp_server $server, $fd) {  
    		try{
    			// 延迟绑定
    			static::onConnet($server, $fd);
    		}catch(\Exception $e) {
    			// 捕捉异常
				\Swoolefy\Core\SwoolefyException::appException($e);
    		}
		});

		//监听数据接收事件
		$this->tcpserver->on('receive', function(tcp_server $server, $fd, $reactor_id, $data) {
			try{
				// 服务端为length检查包
				if(self::isPackLength()) {
					$recv = $this->pack->depack($server, $fd, $reactor_id, $data);
				}else {
					// 服务端为eof检查包
					$recv = $this->pack->depackeof($data);
				}
				// 延迟绑定，服务处理实例
				static::onReceive($server, $fd, $reactor_id, $recv);
				return;
    		}catch(\Exception $e) {
    			// 捕捉异常
				\Swoolefy\Core\SwoolefyException::appException($e);
    		}
			
		});

		//处理异步任务
		$this->tcpserver->on('task', function(tcp_server $server, $task_id, $from_worker_id, $data) {
			try{
				$taskdata = swoole_unpack($data);
				// 延迟绑定
				static::onTask($task_id, $from_worker_id, $taskdata);
		    	return;
			}catch(\Exception $e) {
				// 捕捉异常
				\Swoolefy\Core\SwoolefyException::appException($e);
			}
		    
		});

		// 异步任务完成 
		$this->tcpserver->on('finish', function(tcp_server $server, $task_id, $data) {
			try{
				$data = swoole_unpack($data);
				static::onFinish($server, $task_id, $data);
			}catch(\Exception $e) {
				// 捕捉异常
				\Swoolefy\Core\SwoolefyException::appException($e);
			}

		});

		// 关闭连接
		$this->tcpserver->on('close', function(tcp_server $server, $fd) {
			try{
				// 删除缓存的不完整的僵尸式数据包
				$this->pack->delete($fd);
				// 延迟绑定
				static::onClose($server, $fd);
			}catch(\Exception $e) {
				// 捕捉异常
				\Swoolefy\Core\SwoolefyException::appException($e);
			}
		});

		/**
		 * 停止worker进程
		 */
		$this->tcpserver->on('WorkerStop',function(tcp_server $server, $worker_id) {
			// 销毁不完整数据以及
			$this->pack->destroy($server, $worker_id);
			// worker停止时的回调处理
			self::$startCtrl::workerStop($server, $worker_id);

		});

		/**
		 * worker进程异常错误回调函数
		 */
		$this->tcpserver->on('WorkerError',function(tcp_server $server, $worker_id, $worker_pid, $exit_code, $signal) {
			// worker停止的触发函数
			self::$startCtrl::workerError($server, $worker_id, $worker_pid, $exit_code, $signal);
		});

		/**
		 * worker进程退出回调函数，1.9.17+版本
		 */
		if(static::compareSwooleVersion()) {
			$this->tcpserver->on('WorkerExit',function(tcp_server $server, $worker_id) {
				// worker退出的触发函数
				self::$startCtrl::workerExit($server, $worker_id);
			});
		}
		$this->tcpserver->start();
	}
}

