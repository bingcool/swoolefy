<?php
namespace Swoolefy\Core;

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
	 * $pack_check_type pack检查的方式
	 * @var [type]
	 */
	protected static $pack_check_type = null;

	const PACK_CHECK_EOF = 'eof';

	const PACK_CHECK_LENGTH = 'length';

	/**
	 * $_startTime 进程启动时间
	 * @var int
	 */
	protected static $_startTime = 0;

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
		// record start time
		self::$_startTime = date('Y-m-d H:i:s',strtotime('now'));
		
	}

	/**
	 * checkVersion 检查是否安装基础扩展
	 * @return   void
	 */
	public static function checkVersion() {
		if(version_compare(phpversion(), '5.6.0', '<')) {
			throw new \Exception("php version must be > 5.6.0,we suggest use php7.0+ version", 1);
		}

		if(!extension_loaded('swoole')) {
			throw new \Exception("you are not install swoole extentions,please install it where version >= 1.9.17 or >=2.0.5 from https://github.com/swoole/swoole-src", 1);
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
		static::$config['setting'] = self::getSetting();
		return static::$config;
	}
	/**
	 * getSetting 获取swoole的配置项
	 * @return   array
	 */
	public static function getSetting() {
		return static::$setting;
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
		$dir = ucfirst($dir);
		$filePath = __DIR__.'/../'.$dir.'/'.$dir.'_'.'includes.json';
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
		self::$server->table_workers_pid->set('workers_pid',['workers_pid'=>json_encode($workers_pid)]);
	}

	/**
	 * getWorkersPid 获取线上的实时的进程worker_pid与worker_id的映射
	 * @return   
	 */
	public static function getWorkersPid() {
		return json_decode(self::$server->table_workers_pid->get('workers_pid')['workers_pid'], true);
	}

	/**
	 * createTables 默认创建定时器任务的内存表    
	 * @return  void
	 */
	public static function createTables() {
		if(!isset(static::$config['table']) || !is_array(static::$config['table'])) {
			static::$config['table'] = [];
		}

		if(isset(static::$config['table_tick_task']) && static::$config['table_tick_task'] == true) {
			$tables = array_merge(self::$_table_tasks,static::$config['table']);
		}else {
			$tables = static::$config['table'];
		}
		
		foreach($tables as $k=>$row) {
			$table = new \swoole_table($row['size']);
			foreach($row['fields'] as $p=>$field) {
				switch(strtolower($field[1])) {
					case 'int':
						$table->column($field[0],\swoole_table::TYPE_INT,(int)$field[2]);
					break;
					case 'string':
						$table->column($field[0],\swoole_table::TYPE_STRING,(int)$field[2]);
					break;
					case 'float':
						$table->column($field[0],\swoole_table::TYPE_FLOAT,(int)$field[2]);
					break;
				}
			}
			$table->create();
			self::$server->$k = $table; 
		}
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
}