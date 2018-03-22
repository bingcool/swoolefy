<?php
namespace Swoolefy\Udp;

use Swoole\Server as udp_server;
use Swoolefy\Core\BaseServer;
use Swoolefy\Core\Swfy;

abstract class UdpServer extends BaseServer {
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
	public $udpserver = null;

	/**
	 * $startctrl
	 * @var null
	 */
	public static $startCtrl = null;

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
		self::$server = $this->udpserver = new udp_server(self::$config['host'], self::$config['port'], self::$swoole_process_mode, self::$swoole_socket_type);
		$this->udpserver->set(self::$setting);
		parent::__construct();
		// 初始化启动类
		self::$startCtrl = isset(self::$config['start_init']) ? self::$config['start_init'] : 'Swoolefy\\Tcp\\StartInit';
		
	}

	public function start() {
		/**
		 * start回调
		 */
		$this->udpserver->on('Start',function(tcp_server $server) {
			// 重新设置进程名称
			self::setMasterProcessName(self::$config['master_process_name']);
			// 启动的初始化函数
			self::$startCtrl::start($server);
		});
		/**
		 * managerstart回调
		 */
		$this->udpserver->on('ManagerStart',function(tcp_server $server) {
			// 重新设置进程名称
			self::setManagerProcessName(self::$config['manager_process_name']);
			// 启动的初始化函数
			self::$startCtrl::managerStart($server);
		});

		/**
		 * 启动worker进程监听回调，设置定时器
		 */
		$this->udpserver->on('WorkerStart',function(tcp_server $server, $worker_id) {
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
			// 超全局变量server
       		Swfy::$server = $this->udpserver;
       		Swfy::$config = self::$config;

       		// 单例服务处理实例
       		is_null(self::$service) && self::$service = swoole_pack(self::$config['application_service']::getInstance($config=[]));
			// 启动的初始化函数
			self::$startCtrl::workerStart($server,$worker_id);
			// 延迟绑定
			static::onWorkerStart($server, $worker_id);

		});

		//监听数据接收事件
		$this->udpserver->on('Packet', function(tcp_server $server, $fd, $reactor_id, $data) {
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
		$this->udpserver->on('task', function(tcp_server $server, $task_id, $from_worker_id, $data) {
			try{
				$taskdata = swoole_unpack($data);
				// 延迟绑定
				static::onTask($server, $task_id, $from_worker_id, $taskdata);
			}catch(\Exception $e) {
				// 捕捉异常
				\Swoolefy\Core\SwoolefyException::appException($e);
			}
		    
		});

		// 异步任务完成 
		$this->udpserver->on('finish', function(tcp_server $server, $task_id, $data) {
			try{
				$data = swoole_unpack($data);
				static::onFinish($server, $task_id, $data);
			}catch(\Exception $e) {
				// 捕捉异常
				\Swoolefy\Core\SwoolefyException::appException($e);
			}

		});

		// 关闭连接
		$this->udpserver->on('close', function(tcp_server $server, $fd) {
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
		$this->udpserver->on('WorkerStop',function(tcp_server $server, $worker_id) {
			// 销毁不完整数据以及
			$this->pack->destroy($server, $worker_id);
			// worker停止时的回调处理
			self::$startCtrl::workerStop($server, $worker_id);

		});

		/**
		 * worker进程异常错误回调函数
		 */
		$this->udpserver->on('WorkerError',function(tcp_server $server, $worker_id, $worker_pid, $exit_code, $signal) {
			// worker停止的触发函数
			self::$startCtrl::workerError($server, $worker_id, $worker_pid, $exit_code, $signal);
		});

		/**
		 * worker进程退出回调函数，1.9.17+版本
		 */
		if(static::compareSwooleVersion()) {
			$this->udpserver->on('WorkerExit',function(tcp_server $server, $worker_id) {
				// worker退出的触发函数
				self::$startCtrl::workerExit($server, $worker_id);
			});
		}
		$this->udpserver->start();
	}
}