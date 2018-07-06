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

namespace Swoolefy\Core;

use Swoolefy\Core\Table\TableManager;

class BaseServer {
	/**
	 * $config 
	 * @var null
	 */
	public static $config = [];

	/**
	 * $server swoole服务器对象实例
	 * @var null
	 */
	public static $server = null;

	/**
	 * $Service 
	 * @var null 服务实例，适用于TCP,UDP,RPC
	 */
	public static $service = null;

	/**
	 * $isEnableCoroutine 是否启用协程
	 * @var boolean
	 */
	public static $isEnableCoroutine = false;

	/**
	 * $pack_check_type pack检查的方式
	 * @var [type]
	 */
	protected static $pack_check_type = null;

	const PACK_CHECK_EOF = SWOOLEFY_PACK_CHECK_EOF;

	const PACK_CHECK_LENGTH = SWOOLEFY_PACK_CHECK_LENGTH;

	/**
	 * $_startTime 进程启动时间
	 * @var int
	 */
	protected static $_startTime = 0;

	/**
	 * $swoole_process_model swoole的进程模式，默认swoole_process
	 * @var [type]
	 */
	protected static $swoole_process_mode = SWOOLE_PROCESS;

	/**
	 * $swoole_socket_type swoole的socket设置类型
	 * @var [type]
	 */
	protected static $swoole_socket_type = SWOOLE_SOCK_TCP;

	/**
	 * $_tasks 实时内存表保存数据,所有worker共享
	 * @var null
	 */
	public static $_table_tasks = [
		// 循环定时器内存表
		'table_ticker' => [
			// 每个内存表建立的行数
			'size' => 4,
			// 字段
			'fields'=> [
				['tick_tasks','string',8096]
			]
		],
		// 一次性定时器内存表
		'table_after' => [
			'size' => 4,
			'fields'=> [
				['after_tasks','string',8096]
			]
		],
		//$_workers_pids 记录映射进程worker_pid和worker_id的关系 
		'table_workers_pid' => [
			'size' => 1,
			'fields'=> [
				['workers_pid','string',512]
			]
		],
	];

	/**
	 * __construct 初始化swoole的内置服务与检查
	 */
	public function __construct() {
		// set timeZone
		self::setTimeZone(); 
		// include common function
		self::setCommonFunction();
		// check extensions
		self::checkVersion();
		// check is run on cli
		self::checkSapiEnv();
		// create table
		self::createTables();
		// check pack type
		self::checkPackType();
		// check coroutine
		self::enableCoroutine();
		// record start time
		self::$_startTime = date('Y-m-d H:i:s',strtotime('now'));
		
	}

	/**
	 * checkVersion 检查是否安装基础扩展
	 * @return   void
	 */
	public static function checkVersion() {
		if(version_compare(phpversion(), '5.6.0', '<')) {
			throw new \Exception("php version must be >= 7.0, we suggest use php7.1+ version", 1);
		}

		if(!extension_loaded('swoole')) {
			throw new \Exception("you are not install swoole extentions,please install it where version >= 1.9.17 or >=4.0.1 from https://github.com/swoole/swoole-src", 1);
		}

		if(!extension_loaded('swoole_serialize')) {
			throw new \Exception("you are not install swoole_serialize extentions,please install it where from https://github.com/swoole/swoole_serialize", 1);
		}

		if(!extension_loaded('pcntl')) {
			throw new \Exception("you are not install pcntl extentions,please install it", 1);
		}

		if(!extension_loaded('posix')) {
			throw new \Exception("you are not install posix extentions,please install it", 1);
		}

		if(!extension_loaded('zlib')) {
			throw new \Exception("you are not install zlib extentions,please install it", 1);
		}

		if(!extension_loaded('mbstring')) {
			throw new \Exception("you are not install mbstring extentions,please install it", 1);
		}
	}

	/**
	 * setMasterProcessName 设置主进程名称
	 * @param  string  $master_process_name
	 */
	public static function setMasterProcessName($master_process_name) {
		swoole_set_process_name($master_process_name);
	}

	/**
	 * setManagerProcessName 设置管理进程的名称
	 * @param  string  $manager_process_name
	 */
	public static function setManagerProcessName($manager_process_name) {
		swoole_set_process_name($manager_process_name);
	}

	/**
	 * setWorkerProcessName 设置worker进程名称
	 * @param  string  $worker_process_name
	 * @param  int  $worker_id          
	 * @param  int  $worker_num         
	 */
	public static function setWorkerProcessName($worker_process_name, $worker_id, $worker_num=1) {
		// 设置worker的进程
		if($worker_id >= $worker_num) {
            swoole_set_process_name($worker_process_name."-task".$worker_id);
        }else {
            swoole_set_process_name($worker_process_name."-worker".$worker_id);
        }

	}

	/**
	 * startInclude 设置需要在workerstart启动时加载的配置文件
	 * @param  array  $includes 
	 * @return   void
	 */
	public static function startInclude() {
		$includeFiles = isset(static::$config['include_files']) ? static::$config['include_files'] : [];
		if($includeFiles) {
			foreach($includeFiles as $filePath) {
				include_once $filePath;
			}
		}
	}

	/**
	 * setWorkerUserGroup 设置worker进程的工作组，默认是root
	 * @param  string $worker_user
	 */
	public static function setWorkerUserGroup($worker_user=null) {
		if(!isset(static::$setting['user'])) {
			if($worker_user) {
				$userInfo = posix_getpwnam($worker_user);
				if($userInfo) {
					posix_setuid($userInfo['uid']);
					posix_setgid($userInfo['gid']);
				}
			}
		}
	}

	/**
	 * filterFaviconIcon google浏览器会自动发一次请求/favicon.ico,在这里过滤掉
	 * @return  void
	 */
	public static function filterFaviconIcon($request,$response) {
		if($request->server['path_info'] == '/favicon.ico' || $request->server['request_uri'] == '/favicon.ico') {
            return $response->end();
       	}
	}

	/**
	 * getStartTime 服务启动时间
	 * @return   time
	 */
	public static function getStartTime() {
		return self::$_startTime;
	}

	/**
	 * getConfig 获取服务的全部配置
	 * @return   array
	 */
	public static function getConf() {
		return static::$config;
	}
	/**
	 * getSetting 获取swoole的配置项
	 * @return   array
	 */
	public static function getSetting() {
		return self::$config['setting'];
	}

	/**
	 * getSwooleVersion 获取swoole的版本
	 * @return   string
	 */
	public static function getSwooleVersion() {
    	return swoole_version();
    }

	/**
	 * getLastError 返回最后一次的错误代码
	 * @return   int
	 */
	public static function getLastError() {
		return self::$server->getLastError();
	}

	/**
	 * getLastErrorMsg 获取swoole最后一次的错误信息
	 * @return   string
	 */
	public static function getLastErrorMsg() {
		$code = swoole_errno();
		return swoole_strerror($code);
	}

	/**
	 * getLocalIp 获取本地ip
	 * @return   string
	 */
	public static function getLocalIp() {
		return swoole_get_local_ip();	
	}

	/**
	 * getLocalMac 获取本机mac地址
	 * @return   array
	 */
	public static function getLocalMac() {
		return swoole_get_local_mac();
	}

	/**
	 * getStatus 获取swoole的状态信息
	 * @return   array
	 */
	public static function getStats() {
		return self::$server->stats();
	}

	/**
	 * setTimeZone 设置时区
	 * @return   void
	 */
	public static function setTimeZone() {
		// 默认
		$timezone = 'PRC';
		if(isset(static::$config['time_zone'])) {
			$timezone = static::$config['time_zone'];
		}
		date_default_timezone_set($timezone);
		return;
	}

	/**
	 * clearCache 清空字节缓存
	 * @return  void
	 */
	public static function clearCache() {
		if(function_exists('apc_clear_cache')){
        	apc_clear_cache();
    	}
	    if(function_exists('opcache_reset')){
	        opcache_reset();
	    }
	}

	/**
	 * getIncludeFiles 获取woker启动前已经加载的文件
	 * @param   string $dir
	 * @return   void
	 */
	public static function getIncludeFiles($dir='Http') {
		if(isset(static::$setting['log_file'])) {
			$path = pathinfo(static::$setting['log_file'], PATHINFO_DIRNAME);
			$dir = strtolower($dir);
			$filePath = $path.'/includes.json';
		}else {
			$dir = ucfirst($dir);
			$filePath = __DIR__.'/../'.$dir.'/includes.json';
		}
		
		$includes = get_included_files();
		if(is_file($filePath)) {
			@unlink($filePath);	
		}
		@file_put_contents($filePath, json_encode($includes));
		@chmod($filePath,0766);
	}

	/**
	 * setWorkersPid 记录worker对应的进程worker_pid与worker_id的映射
	 * @param    $worker_id  
	 * @param    $worker_pid 
	 */
	public static function setWorkersPid($worker_id, $worker_pid) {
		$workers_pid = self::getWorkersPid();
		$workers_pid[$worker_id] = $worker_pid;
		TableManager::set('table_workers_pid', 'workers_pid', ['workers_pid'=>json_encode($workers_pid)]);
	}

	/**
	 * getWorkersPid 获取线上的实时的进程worker_pid与worker_id的映射
	 * @return   
	 */
	public static function getWorkersPid() {
		return json_decode(TableManager::get('table_workers_pid', 'workers_pid', 'workers_pid'), true);
	}

	/**
	 * createTables 默认创建定时器任务的内存表    
	 * @return  void
	 */
	public static function createTables() {
		if(!isset(static::$config['table']) || !is_array(static::$config['table'])) {
			static::$config['table'] = [];
		}

		if(isset(static::$config['open_table_tick_task']) && static::$config['open_table_tick_task'] == true) {
			$tables = array_merge(self::$_table_tasks,static::$config['table']);
		}else {
			$tables = static::$config['table'];
		}
		//create table
		TableManager::createTable($tables);
	}

	/**
	 * isWorkerProcess 进程是否是worker进程
	 * @param    $worker_id
	 * @return   boolean
	 */
	public static function isWorkerProcess($worker_id) {
		if($worker_id < static::$setting['worker_num']) {
			return true;
		}
		return false;
	}

	/**
	 * isTaskProcess 进程是否是task进程
	 * @param    $worker_id
	 * @return   boolean
	 */
	public static function isTaskProcess($worker_id) {
		return static::isWorkerProcess($worker_id) ? false : true;
	}

	/**
	 * isUseSsl 判断是否使用ssl加密
	 * @return   boolean
	 */
	protected static function isUseSsl() {
		if(isset(static::$setting['ssl_cert_file']) && isset(static::$setting['ssl_key_file'])) {
			return true;
		}
		return false;
	}

	/**
	 * setSwooleSockType 设置socket的类型
	 */
	protected static function setSwooleSockType() {
		if(isset(static::$setting['swoole_process_mode']) && static::$setting['swoole_process_mode'] == SWOOLE_BASE) {
			self::$swoole_process_mode = SWOOLE_BASE;
		}

		if(self::isUseSsl()) {
			self::$swoole_socket_type = SWOOLE_SOCK_TCP | SWOOLE_SSL;
		}
		return;
	}

	/**
	 * serviceType 获取当前主服务器使用的协议
	 * @return   mixed
	 */
	public static function getServiceProtocol() {
		// websocket
		if(static::$server instanceof \Swoole\WebSocket\Server) {
			return SWOOLEFY_WEBSOCKET;
		}else if(static::$server instanceof \Swoole\Http\Server) {
			return SWOOLEFY_HTTP;
		}else if(static::$server instanceof \Swoole\Server) {
			if(self::$swoole_socket_type == SWOOLE_SOCK_UDP) {
				return SWOOLEFY_UDP;
			}
			return SWOOLEFY_TCP;
		}
		return false;

	} 

	/**
	 * setCommonFunction 底层的公共函数库
	 * @return  void
	 */
	public static function setCommonFunction() {
		// 包含核心公共的函数库
		include_once __DIR__.'/Func/function.php';
	}

	/**
	 * swooleVersion 判断swoole是否大于某个版本
	 * @param    $version
	 * @return   boolean
	 */
	public static function compareSwooleVersion($version = '1.9.15') {
		if(isset(static::$config['swoole_version']) && !empty(static::$config['swoole_version'])) {
			$version = static::$config['swoole_version'] ;
		}
		if(version_compare(swoole_version(), $version, '>')) {
			return true;
		}

		return false;
	}

	/**
	 * checkSapiEnv 判断是否是cli模式启动
	 * @return void
	 */
	public static function checkSapiEnv() {
        // Only for cli.
        if(php_sapi_name() != 'cli') {
            throw new \Exception("only run in command line mode \n", 1);
        }
    }

    /**
     * checkPackType 设置pack检查类型
     * @return void
     */
    protected static function checkPackType() {
    	if(isset(static::$setting['open_eof_check']) || isset(static::$setting['package_eof']) || isset(static::$setting['open_eof_split'])) {
    		self::$pack_check_type = self::PACK_CHECK_EOF;
    	}else {
    		self::$pack_check_type = self::PACK_CHECK_LENGTH;
    	}
    	if(self::$pack_check_type) {
    		self::$server->pack_check_type = self::$pack_check_type;
    	}
    	
    }

    /**
     * usePackEof 是否是pack的eof
     * @return boolean
     */
    protected static function isPackEof() {
    	if(self::$pack_check_type == self::PACK_CHECK_EOF) {
    		return true;
    	}
    	return false;
    }

    /**
     * isPackLength 是否是pack的length
     * @return boolean
     */
    protected static function isPackLength() {
    	if(self::$pack_check_type == self::PACK_CHECK_LENGTH) {
    		if(!isset(static::$config['packet']['server'])) {
    			throw new \Exception("you must set ['packet']['server'] in the config", 1);
    			
    		}
    		return true;
    	}
    	return false;
    }

    /**
     * enableCoroutine 
     * @return 
     */
    public static function enableCoroutine() {
    	if(version_compare(swoole_version(), '4.0.0', '>')) {
    		self::$isEnableCoroutine = true;
    		return;
    	}else {
    		// 低于4.0版本不能使用协程
    		self::$isEnableCoroutine = false;
    		return;
    	}
    	
    }

    /**
     * isEnableCoroutine
     * @return boolean
     */
	public static function canEnableCoroutine() {
		return self::$isEnableCoroutine;
    }

    /**
     * catchException 
     * @param  Exception $e
     * @return void
     */
    public static function catchException($e) {
    	$ExceptionHanderClass = 'Swoolefy\\Core\\SwoolefyException';
    	if(isset(self::$config['exception_hander_class']) && !empty(self::$config['exception_hander_class'])) {
			$ExceptionHanderClass = self::$config['exception_hander_class'];
		}

		$ExceptionHanderClass::appException($e);
    }
}