<?php
namespace Swoolefy\Tcp;

use Swoole\Server as tcp_server;
use Swoolefy\Core\BaseServer;
use Swoolefy\Core\Swfy;
use Swoolefy\Core\Pack;

// 如果直接通过php WebsocketServer.php启动时，必须include的vendor/autoload.php
if(isset($argv) && $argv[0] == basename(__FILE__)) {
	include_once '../../vendor/autoload.php';
}

class TcpServer extends BaseServer {
	/**
	 * $setting
	 * @var array
	 */
	public static $setting = [
		'reactor_num' => 1, //reactor thread num
		'worker_num' => 2,    //worker process num
		'max_request' => 5,
		'task_worker_num' =>1,
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
	 * $tcpserver 
	 * @var null
	 */
	public $tcpserver = null;

	/**
	 * $tcpclient 
	 * @var null
	 */
	public $tcp_client = null;

	/**
	 * $gateway_config 
	 * @var array
	 */
	public $rpc_config = [
		['host'=>'127.0.0.1','port'=>9998]
	];

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
	 * __construct
	 * @param array $config
	 */
	public function __construct(array $config=[]) {
		self::clearCache();
		self::$config = array_merge(
					include(__DIR__.'/config.php'),
					$config
			);
		self::$server = $this->tcpserver = new tcp_server(self::$config['host'], self::$config['port']);
		self::$config['setting'] = self::$setting = array_merge(self::$setting, self::$config['setting']);
		$this->tcpserver->set(self::$setting);
		parent::__construct();

		// 创建一个channel通道,worker进程共享内存
		$this->channel = new \Swoole\Channel(1024 * 256);

		// 设置Pack包处理对象
		$this->pack = new Pack(self::$server);
		$this->pack->serialize_type = Pack::DECODE_JSON;

		// 初始化启动类
		self::$startCtrl = isset(self::$config['start_init']) ? self::$config['start_init'] : 'Swoolefy\\Websocket\\StartInit';	
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
			self::getIncludeFiles('Tcp');
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

       		// is_null(self::$App) && self::$App = swoole_pack(self::$config['application_index']::getInstance($config=[]));
       		
			// 启动的初始化函数
			self::$startCtrl::workerStart($server,$worker_id);
			//tcp的异步client连接tcp的server,只能是在worker进程中
			// self::isWorkerProcess($worker_id) && self::registerRpc();
			
		});

		// tcp连接
		$this->tcpserver->on('connect', function (tcp_server $server, $fd) {  
    		try{

    		}catch(\Exception $e) {
    			// 捕捉异常
				\Swoolefy\Core\SwoolefyException::appException($e);
    		}
		});

		//监听数据接收事件
		$this->tcpserver->on('receive', function(tcp_server $server, $fd, $reactor_id, $data) {
			try{
				$res = $this->pack->depack($server, $fd, $reactor_id, $data);
				if($res) {
					list($header, $data) = $res;
				}

				// 打包数据返回给客户端
				$sendData = $this->pack->enpack($data, $header, Pack::DECODE_JSON);
				$server->send($fd, $sendData);
				return;

    		}catch(\Exception $e) {
    			// 捕捉异常
				\Swoolefy\Core\SwoolefyException::appException($e);
    		}
			
	
		});

		//处理异步任务
		$this->tcpserver->on('task', function(tcp_server $server, $task_id, $from_worker_id, $data) {
			try{
				// list($fd,$data) = $data;
		    	//返回任务执行的结果
		    	return $data;
			}catch(\Exception $e) {
				// 捕捉异常
				\Swoolefy\Core\SwoolefyException::appException($e);
			}
		    
		});

		// 异步任务完成 
		$this->tcpserver->on('finish', function(tcp_server $server, $task_id, $data) {
			try{
				
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

	/**
	 * registerRpc 
	 * @return   [type]        [description]
	 */
	public function registerRpc() {
		$this->tcp_client = new \swoole_client(SWOOLE_SOCK_TCP, SWOOLE_SOCK_ASYNC);
		//注册连接成功回调
		$this->tcp_client->on("connect", function($cli){
		    // 
		});

		//注册数据接收回调
		$this->tcp_client->on("receive", function($cli, $data){

		});

		//注册连接失败回调
		$this->tcp_client->on("error", function($cli){
		});

		//注册连接关闭回调
		$this->tcp_client->on("close", function($cli){
		});

		//发起连接
		$this->tcp_client->connect('127.0.0.1', 9998, 0.5);
	}

}

if(isset($argv) && $argv[0] == basename(__FILE__)) {
	$tcpserver = new TcpServer();
	$tcpserver->start();
}
