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

namespace Swoolefy\Core;

use Swoolefy\Core\Table\TableManager;
use Swoolefy\Core\Memory\AtomicManager;

class BaseServer {

    /**
     * check eof
     */
    const PACK_CHECK_EOF = SWOOLEFY_PACK_CHECK_EOF;

    /**
     * check length
     */
    const PACK_CHECK_LENGTH = SWOOLEFY_PACK_CHECK_LENGTH;

    /**
	 * $config 
	 * @var array
	 */
	public static $config = [];

	/**
	 * $server swoole server
	 * @var \Swoole\Server
	 */
	public static $server = null;

	/**
	 * $Service 
	 * @var null 服务实例，适用于TCP,UDP,RPC
	 */
	protected static $server_name = null;

	/**
	 * $isEnableCoroutine 是否启用协程
	 * @var boolean
	 */
	protected static $isEnableCoroutine = false;

	/**
	 * $pack_check_type pack检查的方式
	 * @var
	 */
	protected static $pack_check_type = null;

	/**
	 * $startTime 进程启动时间
	 * @var int
	 */
	protected static $startTime = 0;

	/**
	 * $swoole_process_model swoole的进程模式，默认swoole_process
	 * @var int
	 */
	protected static $swoole_process_model = SWOOLE_PROCESS;

	/**
	 * $swoole_socket_type swoole的socket设置类型
	 * @var int
	 */
	protected static $swoole_socket_type = SWOOLE_SOCK_TCP;

    /**
     * @var string 默认启动处理类
     */
	protected static $start_handler_class = 'Swoolefy\\Core\\EventHandler';

    /**
     * $startCtrl
     * @var EventHandler
     */
    protected $startCtrl = null;

    /**
     * @var array
     */
    protected $coroutine_setting = [];

	/**
	 * $_tasks 实时内存表保存数据,所有worker共享
	 * @var array
	 */
	protected static $_table_tasks = [
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
	];

    /**
     * $_table_worker_pid_map 记录映射进程worker_pid和worker_id的关系
     * @var array
     */
	protected static $_table_worker_pid_map = [
        'table_workers_pid' => [
            'size' => 1,
            'fields'=> [
                ['workers_pid','string',512]
            ]
        ]
    ];

    /**
     * __construct 初始化swoole的内置服务与检查
     * @throws \Exception
     */
	public function __construct() {
		// set config
		Swfy::setConf(self::$config);
        // start runtime Coroutine
        self::setCoroutineSetting(self::$config['coroutine_setting'] ?? []);
		// set timeZone
		self::setTimeZone();
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
		// enable sys collector
		self::setAutomicOfRequest();
		// record start time
		self::$startTime = date('Y-m-d H:i:s');
        // start init
        $this->startCtrl = self::eventHandler();
        (new \Swoolefy\Core\EventApp())->registerApp(function() {
            $this->startCtrl->init();
        });

	}

	/**
	 * checkVersion 检查是否安装基础扩展
     * @return void
     * @throws \Exception
	 */
	public static function checkVersion() {
		if(version_compare(phpversion(), '7.2.0', '<')) {
			throw new \Exception("php version must be >= 7.2.0, we suggest use php7.2+ version", 1);
		}

		if(!extension_loaded('swoole')) {
			throw new \Exception("Missing install swoole extentions,please install swoole(suggest 4.5.0+) from https://github.com/swoole/swoole-src", 1);
		}

		if(!extension_loaded('pcntl')) {
			throw new \Exception("Missing install pcntl extentions,please install it", 1);
		}

		if(!extension_loaded('posix')) {
			throw new \Exception("Missing install posix extentions,please install it", 1);
		}

		if(!extension_loaded('zlib')) {
			throw new \Exception("Missing install zlib extentions,please install it", 1);
		}

		if(!extension_loaded('mbstring')) {
			throw new \Exception("Missing install mbstring extentions,please install it", 1);
		}
	}

	/**
	 * setMasterProcessName 设置主进程名称
	 * @param  string  $master_process_name
	 */
	public static function setMasterProcessName(string $master_process_name) {
		cli_set_process_title(static::getAppPrefix().':'.$master_process_name);
	}

	/**
	 * setManagerProcessName 设置管理进程的名称
	 * @param  string  $manager_process_name
	 */
	public static function setManagerProcessName($manager_process_name) {
        cli_set_process_title(static::getAppPrefix().':'.$manager_process_name);
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
            cli_set_process_title(static::getAppPrefix().':'.$worker_process_name."-task".$worker_id);
        }else {
            cli_set_process_title(static::getAppPrefix().':'.$worker_process_name."-worker".$worker_id);
        }

	}

    /**
     * @param array $setting
     * @return bool
     */
	public static function setCoroutineSetting(array $setting)
    {
        $setting['hook_flags'] = self::getHookFlags($setting);
        $setting = array_merge(\Swoole\Coroutine::getOptions() ?? [], $setting);
        !empty($setting) && \Swoole\Coroutine::set($setting);
        return true;
    }

    /**
     * @return string
     */
	public static function getAppName() {
        $appName = '';
        if(defined('APP_NAME')) {
            $appName = APP_NAME;
        }
        return $appName;
    }

    /**
     * @return string
     */
    public static function getAppPrefix()
    {
        return self::getAppName().'-swoolefy';
    }

	/**
	 * startInclude 设置需要在workerStart启动时加载的配置文件
	 * @param  array  $includes 
	 * @return void
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
     * @var \Swoole\Http\Request $request
     * @var \Swoole\Http\Response $response
	 * @return  mixed
	 */
	public static function filterFaviconIcon($request, $response) {
		if($request->server['path_info'] == '/favicon.ico' || $request->server['request_uri'] == '/favicon.ico') {
            return $response->end();
       	}
	}

	/**
	 * getStartTime 服务启动时间
	 * @return int
	 */
	public static function getStartTime() {
		return self::$startTime;
	}

    /**
     * setServerName
     * @param string $server_name
     */
	public static function setServerName($server_name = null) {
        self::$server_name = $server_name;
    }

	/**
	 * getServerName 
	 * @return string
	 */
	public static function getServerName() {
		return self::$server_name;
	}

	/**
	 * getConfig 获取服务的全部配置
	 * @return array
	 */
	public static function getConf() {
		return static::$config;
	}

    /**
     * getAppConf
     * @return array
     */
	public static function getAppConf() {
	    return static::$config['app_conf'];
    }

    /**
     * setAppConf
     * @param array $config
     */
    public static function setAppConf(array $conf = []) {
    	if(!empty($conf)) {
    		static::$config['app_conf'] = $conf;
    	}
    }

	/**
	 * getSwooleSetting 获取swoole的配置项
	 * @return array
	 */
	public static function getSwooleSetting() {
		return self::$config['setting'];
	}

	/**
	 * getServer 
	 * @return \Swoole\Server
	 */
	public static function getServer() {
		return self::$server;
	}

	/**
	 * getSwooleVersion 获取swoole的版本
	 * @return string
	 */
	public static function getSwooleVersion() {
    	return swoole_version();
    }

	/**
	 * getLastError 返回最后一次的错误代码
	 * @return int
	 */
	public static function getLastError() {
		return self::$server->getLastError();
	}

	/**
	 * getLastErrorMsg 获取swoole最后一次的错误信息
	 * @return string
	 */
	public static function getLastErrorMsg() {
		$code = swoole_errno();
		return swoole_strerror($code);
	}

	/**
	 * getLocalIp 获取本地ip
	 * @return string
	 */
	public static function getLocalIp() {
		return swoole_get_local_ip();	
	}

	/**
	 * getLocalMac 获取本机mac地址
	 * @return array
	 */
	public static function getLocalMac() {
		return swoole_get_local_mac();
	}

	/**
	 * getStatus 获取swoole的状态信息
	 * @return array
	 */
	public static function getStats() {
		return self::$server->stats();
	}

	/**
	 * setTimeZone 设置时区
	 * @return boolean
	 */
	public static function setTimeZone() {
		// 默认
		$timezone = static::$config['time_zone'] ?? 'PRC';
		date_default_timezone_set($timezone);
		return true;
	}

	/**
	 * clearCache 清空字节缓存
	 * @return void
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
	 * @param string $dir
	 * @return void
	 */
	public static function getIncludeFiles($worker_id) {
		if(isset(static::$setting['log_file']) && $worker_id == 0) {
			$path = pathinfo(static::$setting['log_file'], PATHINFO_DIRNAME);
			$filePath = $path.'/includes.json';
            $includes = get_included_files();
            if(is_file($filePath)) {
                @unlink($filePath);
            }
            @file_put_contents($filePath, json_encode($includes));
            @chmod($filePath,0766);
		}
	}

	/**
	 * setWorkersPid 记录worker对应的进程worker_pid与worker_id的映射
	 * @param  int $worker_id
	 * @param  int $worker_pid
     * @return void
	 */
	public static function setWorkersPid($worker_id, $worker_pid) {
		$workers_pid = self::getWorkersPid();
		$workers_pid[$worker_id] = $worker_pid;
		TableManager::set('table_workers_pid', 'workers_pid', ['workers_pid'=>json_encode($workers_pid)]);
	}

	/**
	 * getWorkersPid 获取线上的实时的进程worker_pid与worker_id的映射
	 * @return array
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

        $table_task = array_merge(static::$_table_worker_pid_map, static::$config['table']);

        if(isset(static::$config['enable_table_tick_task'])) {
            $is_enable_table_tick_task = filter_var(static::$config['enable_table_tick_task'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if($is_enable_table_tick_task) {
                $table_task = array_merge(self::$_table_tasks, static::$_table_worker_pid_map, static::$config['table']);
            }
		}
		//create table
        $table_task && TableManager::createTable($table_task);
	}

	/**
	 * isWorkerProcess 进程是否是worker进程
	 * @param   int $worker_id
	 * @return  boolean
	 */
	public static function isWorkerProcess($worker_id) {
		if($worker_id < static::$setting['worker_num']) {
			return true;
		}
		return false;
	}

	/**
	 * isTaskProcess 进程是否是task进程
	 * @param   int $worker_id
	 * @return  boolean
	 */
	public static function isTaskProcess($worker_id) {
		return static::isWorkerProcess($worker_id) ? false : true;
	}

	/**
	 * isUseSsl 判断是否使用ssl加密
	 * @return boolean
	 */
	protected static function isUseSsl() {
		if(isset(static::$setting['ssl_cert_file']) && isset(static::$setting['ssl_key_file'])) {
			return true;
		}
		return false;
	}

	/**
	 * setSwooleSockType 设置socket的类型
     * @return void
	 */
	protected static function setSwooleSockType() {
		if(isset(static::$setting['swoole_process_mode']) && static::$setting['swoole_process_mode'] == SWOOLE_BASE) {
			self::$swoole_process_model = SWOOLE_BASE;
		}

		if(self::isUseSsl()) {
			self::$swoole_socket_type = SWOOLE_SOCK_TCP | SWOOLE_SSL;
		}
	}

	/**
	 * serviceType 获取当前主服务器使用的协议,只需计算一次即可，寄存static变量
	 * @return  mixed
	 */
	public static function getServiceProtocol() {
	    static $protocol;
	    if($protocol) {
	        return $protocol;
        }
		if(static::$server instanceof \Swoole\WebSocket\Server) {
			$protocol = SWOOLEFY_WEBSOCKET;
            return $protocol;
		}else if(static::$server instanceof \Swoole\Http\Server) {
            $protocol = SWOOLEFY_HTTP;
            return $protocol;
		}else if(static::$server instanceof \Swoole\Server) {
			if(self::$swoole_socket_type == SWOOLE_SOCK_UDP) {
                $protocol = SWOOLEFY_UDP;
			}else {
			    if(isset(static::$config['setting']['open_mqtt_protocol']) && (bool)static::$config['setting']['open_mqtt_protocol'] === true) {
                    $protocol = SWOOLEFY_MQTT;
                }else {
                    $protocol = SWOOLEFY_TCP;
                }
            }
            return $protocol;
		}
		return false;
	}

	/**
	 * swooleVersion 判断swoole是否大于某个版本
	 * @param string  $version
	 * @return boolean
	 */
	public static function compareSwooleVersion($version = '4.2.0') {
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
     * @throws \Exception
	 * @return void
	 */
	public static function checkSapiEnv() {
        // Only for cli.
        if(php_sapi_name() != 'cli') {
            throw new \Exception("Swoolefy only run in command line mode \n", 1);
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
    public static function isPackEof() {
    	if(self::$pack_check_type == self::PACK_CHECK_EOF) {
    		return true;
    	}
    	return false;
    }

    /**
     * isPackLength 是否是pack的length
     * @throws mixed
     * @return boolean
     */
    public static function isPackLength() {
    	if(self::$pack_check_type == self::PACK_CHECK_LENGTH) {
    		if(!isset(static::$config['packet']['server'])) {
    			throw new \Exception("If you want to use RPC server, you must set ['packet']['server'] in the config", 1);
    		}
    		return true;
    	}
    	return false;
    }

    /**
     * enableCoroutine 
     * @return void
     */
    public static function enableCoroutine() {
    	if(version_compare(swoole_version(), '4.2.0', '>')) {
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
     * @return bool
     */
    public static function runtimeEnableCoroutine()
    {
        return self::setCoroutineSetting(self::$config['coroutine_setting'] ?? []);
    }

    /**
     * getHookFlags
     */
    public static function getHookFlags(array $coroutine_setting = [])
    {
        $hook_flags = $coroutine_setting['hook_flags'] ?? '';
        if(empty($hook_flags))
        {
            if(version_compare(swoole_version(),'4.7.0', '>='))
            {
                $hook_flags = SWOOLE_HOOK_ALL | SWOOLE_HOOK_NATIVE_CURL;
            }else if(version_compare(swoole_version(),'4.6.0', '>='))
            {
                $hook_flags = SWOOLE_HOOK_ALL ^ SWOOLE_HOOK_CURL | SWOOLE_HOOK_NATIVE_CURL;
            }else
            {
                $hook_flags = SWOOLE_HOOK_ALL ^ SWOOLE_HOOK_CURL;
            }
        }

        return $hook_flags;
    }

    /**
     * catchException
     * @param  \Throwable $e
     * @return void
     */
    public static function catchException($e) {
    	$exceptionHandlerClass = '\\Swoolefy\\Core\\SwoolefyException';
    	if(isset(self::$config['exception_handler']) && !empty(self::$config['exception_handler'])) {
			$exceptionHandlerClass = self::$config['exception_handler'];
		}
        $exceptionHandlerClass::appException($e);
    }

    /**
     * getExceptionClass 
     * @return string
     */
    public static function getExceptionClass() {
        $exceptionHandlerClass = '\\Swoolefy\\Core\\SwoolefyException';
        // 获取协议层配置
        if(isset(self::$config['exception_handler']) && !empty(self::$config['exception_handler'])) {
            $exceptionHandlerClass = self::$config['exception_handler'];
        }
        return $exceptionHandlerClass;
    }
    
    /**
     * setAutomicOfRequest 创建计算请求的原子计算实例,必须依赖于EnableSysCollector = true，否则设置没有意义,不生效
     * @param boolean
     */
    public static function setAutomicOfRequest() {
    	if(self::isEnableSysCollector() && self::isEnablePvCollector()) {
    		AtomicManager::getInstance()->addAtomicLong('atomic_request_count');
    		return true;
    	}
    	return false;
    }

    /**
     * isEnableSysCollector
     * @return boolean
     */
    public static function isEnableSysCollector() {
    	static $isEnableSysCollector;
    	if($isEnableSysCollector) {
    		return true;
    	}
    	if(isset(self::$config['enable_sys_collector'])) {
            $isEnableSysCollector = filter_var(self::$config['enable_sys_collector'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if($isEnableSysCollector === null) {
                $isEnableSysCollector = false;
            }
    	}else {
    		$isEnableSysCollector = false;
    	}
    	return $isEnableSysCollector;
    }

    /**
     * isEnablePvCollector 是否启用计算请求次数
     * @return boolean
     */
    public static function isEnablePvCollector() {
        static $isEnablePvCollector;
        if($isEnablePvCollector) {
            return true;
        }
        if(isset(self::$config['enable_pv_collector'])) {
            $isEnablePvCollector = filter_var(self::$config['enable_pv_collector'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if($isEnablePvCollector === null) {
                $isEnablePvCollector = false;
            }
        }else {
            $isEnablePvCollector = false;
        }
        return $isEnablePvCollector;
    }

    /**
     * isEnableReload
     * @return boolean
     */
    public static function isEnableReload() {
        $isEnableReload = false;
        if(isset(self::$config['reload_conf']) && isset(self::$config['reload_conf']['enable_reload'])) {
            $isEnableReload = filter_var(self::$config['reload_conf']['enable_reload'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if($isEnableReload === null) {
                $isEnableReload = false;
            }
        }
        return $isEnableReload;
    }
    /**
     * isHttpApp
     * @return boolean
     */
    public static function isHttpApp() {
        if(BaseServer::getServiceProtocol() == SWOOLEFY_HTTP) {
            return true;
        }
        return false;
    }

    /**
     * isRpcApp 判断当前应用是否是Tcp
     * @return boolean
     */
    public static function isRpcApp() {
        if(BaseServer::getServiceProtocol() == SWOOLEFY_TCP) {
            return true;
        }
        return false;
    }

    /**
     * isWebsocketApp
     * @return boolean
     */
    public static function isWebsocketApp() {
        if(BaseServer::getServiceProtocol() == SWOOLEFY_WEBSOCKET) {
            return true;
        }
        return false;
    }

    /**
     * isUdpApp
     * @return boolean
     */
    public static function isUdpApp() {
        if(BaseServer::getServiceProtocol() == SWOOLEFY_UDP) {
            return true;
        }
        return false;
    }

    /**
     * @return boolean|mixed
     */
    public static function isTaskEnableCoroutine() {
        static $isTaskEnableCoroutine;
        if(isset($isTaskEnableCoroutine) && is_bool($isTaskEnableCoroutine)) {
            return $isTaskEnableCoroutine;
        }

        $isTaskEnableCoroutine = false;
        if(version_compare(swoole_version(), '4.4.5', '>')) {
            if(isset(self::$config['setting']['task_enable_coroutine'])) {
                $isTaskEnableCoroutine = filter_var(self::$config['setting']['task_enable_coroutine'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if($isTaskEnableCoroutine === null) {
                    $isTaskEnableCoroutine = false;
                }
            }
        }
        return $isTaskEnableCoroutine;
    }

    /**
     * @param $subclass
     * @param $parentclass
     * @return boolean
     */
    public static function isSubclassOf($subclass, $parentclass) {
        if(is_subclass_of($subclass, $parentclass) || trim($subclass,'\\') == trim($parentclass,'\\')) {
            return true;
        }
        return false;
    }

    /**
     * startHandler
     * @return mixed
     * @throws \Exception
     */
    public static function eventHandler() {
        $starHandlerClass = isset(self::$config['event_handler']) ? self::$config['event_handler'] : self::$start_handler_class;
        if(self::isSubclassOf($starHandlerClass, self::$start_handler_class)) {
           return new $starHandlerClass();
        }
        throw new \Exception("Config item of 'event_handler'=>{$starHandlerClass} must extends ".self::$start_handler_class.' class');
    }

    /**
     * requestCount 必须依赖于EnableSysCollector = true，否则设置没有意义，不生效
     * @return boolean
     */
    public static function atomicAdd() {
    	if(self::isEnableSysCollector() && self::isEnablePvCollector()) {
    		$atomic = AtomicManager::getInstance()->getAtomicLong('atomic_request_count');
    		if(is_object($atomic)) {
    			return $atomic->add(1);
    		}
    	}
    	return false;
    }

    /**
     * registerShutdownFunction 注册异常处理函数
     */
    public static function registerShutdownFunction() {
        $exceptionClass = self::getExceptionClass();
        register_shutdown_function($exceptionClass.'::fatalError');
    }

    /**
     * registerErrorHandler
     */
    public static function registerErrorHandler() {
        $exceptionClass = self::getExceptionClass();
        set_error_handler($exceptionClass.'::appError');
    }

    /**
     * beforeRequest
     * @return void
     */
    public static function beforeHandle() {
    	self::atomicAdd();
        // registerErrorHandler
        self::registerErrorHandler();
    }

    /**
     * endRequest 
     * @return void
     */
    public static function endHandler() {}

}