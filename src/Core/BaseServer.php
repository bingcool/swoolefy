<?php
/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | @see https://github.com/bingcool/swoolefy
 * +----------------------------------------------------------------------
 */

namespace Swoolefy\Core;

use Swoolefy\Core\Table\TableManager;
use Swoolefy\Core\Memory\AtomicManager;
use Swoolefy\Script\AbstractKernel;

class BaseServer
{

    /**
     * check eof
     */
    const PACK_CHECK_EOF = SWOOLEFY_PACK_CHECK_EOF;

    /**
     * check length
     */
    const PACK_CHECK_LENGTH = SWOOLEFY_PACK_CHECK_LENGTH;

    /**
     * config
     * @var array
     */
    protected static $config = [];

    /**
     * server
     * @var \Swoole\Server
     */
    protected static $server = null;

    /**
     * service name
     * @var string
     */
    protected static $serverName = null;

    /**
     * @var bool
     */
    protected static $isEnableCoroutine = false;

    /**
     * pack check type
     * @var int
     */
    protected static $pack_check_type = null;

    /**
     * startTime
     * @var int
     */
    protected static $startTime = 0;

    /**
     * process model
     * @var int
     */
    protected static $swooleProcessModel = SWOOLE_PROCESS;

    /**
     * socket type
     * @var int
     */
    protected static $swooleSocketType = SWOOLE_SOCK_TCP;

    /**
     * @var string
     */
    protected static $startHandlerClass = 'Swoolefy\\Core\\EventHandler';

    /**
     * $startCtrl
     * @var EventHandler
     */
    protected $startCtrl = null;

    /**
     * @var array
     */
    public static $tableMemory = [];

    /**
     * memory table
     * @var array
     */
    protected static $_table_tasks = [
        // 循环定时器内存表
        'table_ticker' => [
            // 每个内存表建立的行数
            'size' => 4,
            // 字段
            'fields' => [
                ['tick_tasks', 'string', 8096]
            ]
        ],
        // 一次性定时器内存表
        'table_after' => [
            'size' => 4,
            'fields' => [
                ['after_tasks', 'string', 8096]
            ]
        ],
        // script 脚本记录执行标志，自定义进程不停重启执行
        'table_for_script' => [
            'size' => 1,
            'fields' => [
                ['is_execute_flag', 'int', 1]
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
            'fields' => [
                ['workers_pid', 'string', 512]
            ]
        ]
    ];

    /**
     * __construct
     */
    public function __construct()
    {
        // register handle error
        self::registerErrorHandler();
        // start runtime Coroutine
        self::setCoroutineSetting(self::$config['coroutine_setting'] ?? []);
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
        (new \Swoolefy\Core\EventApp())->registerApp(function () {
            $this->startCtrl->init();
        });

    }

    /**
     * @param $server
     * @param $workerId
     * @return void
     */
    protected function workerStartInit($server, $workerId)
    {
        try {
            // global server
            Swfy::setSwooleServer($server);
            // 启动动态运行时的Coroutine
            self::runtimeEnableCoroutine();
            // 记录主进程加载的公共files,worker重启不会在加载的
            self::getIncludeFiles($workerId);
            // registerShutdown
            self::registerShutdownFunction();
            // 重启worker时，刷新字节cache
            self::clearCache();
            // 重新设置进程名称
            self::setWorkerProcessName(self::$config['worker_process_name'], $workerId, static::$setting['worker_num']);
            // 设置worker工作的进程组
            self::setWorkerUserGroup(self::$config['www_user']);
            // 启动时提前加载文件
            self::startInclude();
            // 记录worker的进程worker_id与worker_pid的映射
            self::setWorkerIdMapPid($workerId, $server->worker_pid);
            // restart model时记录新重启的masterPid
            self::saveRestartModelMasterPid();
        }catch(\Throwable $throwable) {
            self::catchException($throwable);
        }

        (new EventApp())->registerApp(function () use ($server, $workerId) {
            $this->startCtrl->workerStart($server, $workerId);
            static::onWorkerStart($server, $workerId);
        });
    }

    /**
     * checkVersion
     * @return void
     * @throws \Exception
     */
    public static function checkVersion()
    {
        if (version_compare(phpversion(), '7.2.0', '<')) {
            throw new \Exception("php version must be >= 7.2.0, we suggest use php7.2+ version", 1);
        }

        if (!extension_loaded('swoole')) {
            throw new \Exception("Missing install swoole extentions,please install swoole(suggest 4.5.0+) from https://github.com/swoole/swoole-src", 1);
        }

        if (!extension_loaded('pcntl')) {
            throw new \Exception("Missing install pcntl extentions,please install it", 1);
        }

        if (!extension_loaded('posix')) {
            throw new \Exception("Missing install posix extentions,please install it", 1);
        }

        if (!extension_loaded('zlib')) {
            throw new \Exception("Missing install zlib extentions,please install it", 1);
        }

        if (!extension_loaded('mbstring')) {
            throw new \Exception("Missing install mbstring extentions,please install it", 1);
        }
    }

    /**
     * cron调用script时命令中带有option参数 --schedule_model=cron ----cron_script_pid_file=xxxxxxxx
     *
     * @return void
     */
    public static function saveCronScriptPidFile()
    {
        if (SystemEnv::cronScheduleScriptModel()) {
            $cronScriptPidFile = str_replace("--","", AbstractKernel::OPTION_SCHEDULE_CRON_SCRIPT_PID_FILE);
            $pidFile = SystemEnv::getOption($cronScriptPidFile);
            if ($pidFile) {
                file_put_contents($pidFile, Swfy::getMasterPid());
            }
        }
    }

    /**
     * setMasterProcessName
     * @param string $masterProcessName
     */
    public static function setMasterProcessName(string $masterProcessName)
    {
        cli_set_process_title(static::getAppPrefix() . ':' . self::parseProcessName($masterProcessName));
    }

    /**
     * setManagerProcessName
     * @param string $managerProcessName
     */
    public static function setManagerProcessName(string $managerProcessName)
    {
        cli_set_process_title(static::getAppPrefix() . ':' . self::parseProcessName($managerProcessName));
    }

    /**
     * @param $processName
     * @return mixed|string
     */
    private static function parseProcessName($processName)
    {
        if (SystemEnv::isWorkerService()) {
            if (SystemEnv::isDaemonService()) {
                $processName = $processName.'-daemon-php';
            }else if (SystemEnv::isCronService()) {
                $processName = $processName.'-cron-php';
            }else if (SystemEnv::isScriptService()) {
                $processName = $processName.'-script-php';
            }
        }

        return $processName;
    }

    /**
     * setWorkerProcessName
     * @param string $workerProcessName
     * @param int $workerId
     * @param int $workerNum
     */
    public static function setWorkerProcessName(string $workerProcessName, int $workerId, int $workerNum = 1)
    {
        if ($workerId >= $workerNum) {
            cli_set_process_title(static::getAppPrefix() . ':' . $workerProcessName . "-task" . $workerId);
        } else {
            cli_set_process_title(static::getAppPrefix() . ':' . $workerProcessName . "-worker" . $workerId);
        }
    }

    /**
     * @param array $setting
     * @return bool
     */
    public static function setCoroutineSetting(array $setting)
    {
        $setting = array_merge(\Swoole\Coroutine::getOptions() ?? [], $setting);
        !empty($setting) && \Swoole\Coroutine::set($setting);
        return true;
    }

    /**
     * @return string
     */
    public static function getAppName()
    {
        $appName = '';
        if (defined('APP_NAME')) {
            $appName = APP_NAME;
        }
        return $appName;
    }

    /**
     * @return string
     */
    public static function getAppPrefix()
    {
        $serviceName = 'cli';
        if (SystemEnv::isWorkerService()) {
            if (SystemEnv::isDaemonService()) {
                $serviceName = 'daemon';
            } else if (SystemEnv::isCronService()) {
                $serviceName = 'cron';
            } else if (SystemEnv::isScriptService()) {
                $serviceName = 'script';
            }
        }
        return '['.self::getAppName() . '-'.$serviceName.'-swoolefy'.']';
    }

    /**
     * startInclude 设置需要在workerStart启动时加载的配置文件
     * @param array $includes
     * @return void
     */
    public static function startInclude()
    {
        $includeFiles = isset(static::$config['include_files']) ? static::$config['include_files'] : [];
        if ($includeFiles) {
            foreach ($includeFiles as $filePath) {
                include $filePath;
            }
        }
    }

    /**
     * setWorkerUserGroup 设置worker进程的工作组，默认是root
     * @param string $worker_user
     */
    public static function setWorkerUserGroup(string $worker_user = null)
    {
        if ($worker_user) {
            $userInfo = posix_getpwnam($worker_user);
            if ($userInfo) {
                posix_setuid($userInfo['uid']);
                posix_setgid($userInfo['gid']);
            }
        }
    }

    /**
     * filterFaviconIcon
     * @return  mixed
     * @var \Swoole\Http\Response $response
     * @var \Swoole\Http\Request $request
     */
    public static function filterFaviconIcon(\Swoole\Http\Request $request, \Swoole\Http\Response $response)
    {
        if ($request->server['path_info'] == '/favicon.ico' || $request->server['request_uri'] == '/favicon.ico') {
            return $response->end();
        }
        return null;
    }

    /**
     * getStartTime
     * @return int
     */
    public static function getStartTime()
    {
        return self::$startTime;
    }

    /**
     * setServerName
     * @param string $serverName
     */
    public static function setServerName(string $serverName)
    {
        self::$serverName = $serverName;
    }

    /**
     * getServerName
     * @return string
     */
    public static function getServerName()
    {
        return self::$serverName;
    }

    /**
     * getConf
     * @return array
     */
    public static function getConf()
    {
        if (empty(static::$config)) {
            static::$config = SystemEnv::loadGlobalConf();
        }
        return static::$config;
    }

    /**
     * getAppConf
     * @return array
     */
    public static function getAppConf()
    {
        return self::getConf()['app_conf'];
    }

    /**
     * setAppConf
     * @param array $appConf
     */
    public static function setAppConf(array $appConf = [])
    {
        if (!empty($appConf)) {
            static::$config['app_conf'] = $appConf;
        }
    }

    /**
     * 重新加载最新的配置
     *
     * @return void
     */
    public static function reloadGlobalConf()
    {
        static::$config = SystemEnv::loadGlobalConf();
    }

    /**
     * getSwooleSetting
     * @return array
     */
    public static function getSwooleSetting()
    {
        return self::$config['setting'];
    }

    /**
     * getServer
     * @return \Swoole\Server
     */
    public static function getServer()
    {
        return self::$server;
    }

    /**
     * getSwooleVersion
     * @return string
     */
    public static function getSwooleVersion()
    {
        return swoole_version();
    }

    /**
     * getLastError
     * @return int
     */
    public static function getLastError()
    {
        return self::$server->getLastError();
    }

    /**
     * getLastErrorMsg
     * @return string
     */
    public static function getLastErrorMsg()
    {
        $code = swoole_errno();
        return swoole_strerror($code);
    }

    /**
     * getLocalIp
     * @return array
     */
    public static function getLocalIp()
    {
        return swoole_get_local_ip();
    }

    /**
     * getLocalMac
     * @return array
     */
    public static function getLocalMac()
    {
        return swoole_get_local_mac();
    }

    /**
     * getStatus
     * @return array
     */
    public static function getStats()
    {
        return self::$server->stats();
    }

    /**
     * setTimeZone
     * @return bool
     */
    public static function setTimeZone()
    {
        return true;
    }

    /**
     * clearCache
     * @return void
     */
    public static function clearCache()
    {
        if (function_exists('apc_clear_cache')) {
            apc_clear_cache();
        }
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
    }

    /**
     * @param int $workerId
     * @return void
     */
    public static function getIncludeFiles(int $workerId)
    {
        if (isset(static::$setting['log_file']) && $workerId == 0) {
            $path = pathinfo(static::$setting['log_file'], PATHINFO_DIRNAME);
            $filePath = $path . '/includes.json';
            $includes = get_included_files();
            if (is_file($filePath)) {
                @unlink($filePath);
            }
            @file_put_contents($filePath, json_encode($includes));
            @chmod($filePath, 0766);
        }
    }

    /**
     * setWorkerIdMapPid 记录worker对应的进程worker_id与worker_pid的映射
     * @param int $workerId
     * @param int $workerPid
     * @return void
     */
    public static function setWorkerIdMapPid(int $workerId, int $workerPid)
    {
        $workerIdPidArr = self::getWorkerIdMapPid();
        $workerIdPidArr[$workerId] = $workerPid;
        TableManager::set('table_workers_pid', 'workers_pid', ['workers_pid' => json_encode($workerIdPidArr)]);
    }

    /**
     * getWorkersPid 获取线上的实时的进程worker_id与worker_pid的映射
     * @return array
     */
    public static function getWorkerIdMapPid()
    {
        return json_decode(TableManager::get('table_workers_pid', 'workers_pid', 'workers_pid'), true);
    }

    /**
     * @return void
     */
    public static function saveRestartModelMasterPid()
    {
        if (SystemEnv::isRestartModel()) {
            $pidFile = SystemEnv::getRestartModelPidFile();
            file_put_contents($pidFile, Swfy::getMasterPid());
        }
    }

    /**
     * createTables 默认创建定时器任务的内存表
     * @return  void
     */
    public static function createTables()
    {
        if (!isset(static::$config['table']) || !is_array(static::$config['table'])) {
            static::$config['table'] = [];
        }

        $table_task = array_merge(static::$_table_worker_pid_map, static::$config['table']);

        if (isset(static::$config['enable_table_tick_task'])) {
            $is_enable_table_tick_task = filter_var(static::$config['enable_table_tick_task'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($is_enable_table_tick_task) {
                $table_task = array_merge(self::$_table_tasks, static::$_table_worker_pid_map, static::$config['table']);
            }
        }
        //create table
        $table_task && TableManager::createTable($table_task);
    }

    /**
     * isWorkerProcess 进程是否是worker进程
     * @param int $workerId
     * @return  bool
     */
    public static function isWorkerProcess(int $workerId)
    {
        if ($workerId < static::$setting['worker_num']) {
            return true;
        }
        return false;
    }

    /**
     * isTaskProcess 是否task进程
     * @param int $workerId
     * @return bool
     */
    public static function isTaskProcess(int $workerId)
    {
        return static::isWorkerProcess($workerId) ? false : true;
    }

    /**
     * isUseSsl
     * @return bool
     */
    protected static function isUseSsl()
    {
        if (isset(static::$setting['ssl_cert_file']) && isset(static::$setting['ssl_key_file'])) {
            return true;
        }
        return false;
    }

    /**
     * setSwooleSockType
     * @return void
     */
    protected static function setSwooleSockType()
    {
        if (isset(static::$setting['swoole_process_mode']) && static::$setting['swoole_process_mode'] == SWOOLE_BASE) {
            self::$swooleProcessModel = SWOOLE_BASE;
        }

        if (self::isUseSsl()) {
            self::$swooleSocketType = SWOOLE_SOCK_TCP | SWOOLE_SSL;
        }
    }

    /**
     * serviceType 获取当前主服务器使用的协议,只需计算一次即可,寄存static变量
     * @return mixed
     */
    public static function getServiceProtocol()
    {
        static $protocol;
        if ($protocol) {
            return $protocol;
        }
        if (static::$server instanceof \Swoole\WebSocket\Server) {
            $protocol = SWOOLEFY_WEBSOCKET;
            return $protocol;
        } else if (static::$server instanceof \Swoole\Http\Server) {
            $protocol = SWOOLEFY_HTTP;
            return $protocol;
        } else if (static::$server instanceof \Swoole\Server) {
            if (self::$swooleSocketType == SWOOLE_SOCK_UDP) {
                $protocol = SWOOLEFY_UDP;
            } else {
                if (isset(static::$config['setting']['open_mqtt_protocol']) && (bool)static::$config['setting']['open_mqtt_protocol'] === true) {
                    $protocol = SWOOLEFY_MQTT;
                } else {
                    $protocol = SWOOLEFY_TCP;
                }
            }
            return $protocol;
        }
        return false;
    }

    /**
     * compareSwooleVersion
     * @param string $version
     * @return bool
     */
    public static function compareSwooleVersion(string $version = '4.4.0')
    {
        if (isset(static::$config['swoole_version']) && !empty(static::$config['swoole_version'])) {
            $version = static::$config['swoole_version'];
        }
        if (version_compare(swoole_version(), $version, '>')) {
            return true;
        }

        return false;
    }

    /**
     * checkSapiEnv
     * @return void
     * @throws \Exception
     */
    public static function checkSapiEnv()
    {
        // Only for cli.
        if (php_sapi_name() != 'cli') {
            throw new \Exception("Swoolefy only run in command line mode \n", 1);
        }
    }

    /**
     * checkPackType
     * @return void
     */
    protected static function checkPackType()
    {
        if (isset(static::$setting['open_eof_check']) || isset(static::$setting['package_eof']) || isset(static::$setting['open_eof_split'])) {
            self::$pack_check_type = self::PACK_CHECK_EOF;
        } else {
            self::$pack_check_type = self::PACK_CHECK_LENGTH;
        }
    }

    /**
     * usePackEof
     * @return bool
     */
    public static function isPackEof()
    {
        if (self::$pack_check_type == self::PACK_CHECK_EOF) {
            return true;
        }
        return false;
    }

    /**
     * isPackLength
     * @return bool
     * @throws mixed
     */
    public static function isPackLength()
    {
        if (self::$pack_check_type == self::PACK_CHECK_LENGTH) {
            if (!isset(static::$config['packet']['server'])) {
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
    public static function enableCoroutine()
    {
        if (version_compare(swoole_version(), '4.2.0', '>')) {
            self::$isEnableCoroutine = true;
        } else {
            self::$isEnableCoroutine = false;
        }
    }

    /**
     * isEnableCoroutine
     * @return bool
     */
    public static function canEnableCoroutine()
    {
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
     * catchException
     * @param \Throwable $e
     * @return void
     */
    public static function catchException($e)
    {
        $exceptionHandlerClass = '\\Swoolefy\\Core\\SwoolefyException';
        if (isset(self::$config['exception_handler']) && !empty(self::$config['exception_handler'])) {
            $exceptionHandlerClass = self::$config['exception_handler'];
        }
        $exceptionHandlerClass::appException($e);
    }

    /**
     * getExceptionClass
     * @return string
     */
    public static function getExceptionClass()
    {
        $exceptionHandlerClass = '\\Swoolefy\\Core\\SwoolefyException';
        if (isset(self::$config['exception_handler']) && !empty(self::$config['exception_handler'])) {
            $exceptionHandlerClass = self::$config['exception_handler'];
        }
        return $exceptionHandlerClass;
    }

    /**
     * setAutomicOfRequest 创建计算请求的原子计算实例,必须依赖于EnableSysCollector = true,否则设置没有意义,不生效
     * @param bool
     */
    public static function setAutomicOfRequest()
    {
        if (self::isEnableSysCollector() && self::isEnablePvCollector()) {
            AtomicManager::getInstance()->addAtomicLong('atomic_request_count');
            return true;
        }
        return false;
    }

    /**
     * isEnableSysCollector
     * @return bool
     */
    public static function isEnableSysCollector()
    {
        static $isEnableSysCollector;
        if ($isEnableSysCollector) {
            return true;
        }
        if (isset(self::$config['enable_sys_collector'])) {
            $isEnableSysCollector = filter_var(self::$config['enable_sys_collector'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($isEnableSysCollector === null) {
                $isEnableSysCollector = false;
            }
        } else {
            $isEnableSysCollector = false;
        }
        return $isEnableSysCollector;
    }

    /**
     * isEnablePvCollector 是否启用计算请求次数qps统计
     * @return bool
     */
    public static function isEnablePvCollector()
    {
        static $isEnablePvCollector;
        if ($isEnablePvCollector) {
            return true;
        }
        if (isset(self::$config['enable_pv_collector'])) {
            $isEnablePvCollector = filter_var(self::$config['enable_pv_collector'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($isEnablePvCollector === null) {
                $isEnablePvCollector = false;
            }
        } else {
            $isEnablePvCollector = false;
        }
        return $isEnablePvCollector;
    }

    /**
     * isEnableReload
     * @return bool
     */
    public static function isEnableReload()
    {
        $isEnableReload = false;
        if (isset(self::$config['reload_conf']) && isset(self::$config['reload_conf']['enable_reload'])) {
            $isEnableReload = filter_var(self::$config['reload_conf']['enable_reload'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($isEnableReload === null) {
                $isEnableReload = false;
            }
        }
        return $isEnableReload;
    }

    /**
     * isHttpApp
     * @return bool
     */
    public static function isHttpApp()
    {
        if (BaseServer::getServiceProtocol() == SWOOLEFY_HTTP) {
            return true;
        }
        return false;
    }

    /**
     * isRpcApp
     * @return bool
     */
    public static function isRpcApp()
    {
        if (BaseServer::getServiceProtocol() == SWOOLEFY_TCP) {
            return true;
        }
        return false;
    }

    /**
     * isWebsocketApp
     * @return bool
     */
    public static function isWebsocketApp()
    {
        if (BaseServer::getServiceProtocol() == SWOOLEFY_WEBSOCKET) {
            return true;
        }
        return false;
    }

    /**
     * isUdpApp
     * @return bool
     */
    public static function isUdpApp()
    {
        if (BaseServer::getServiceProtocol() == SWOOLEFY_UDP) {
            return true;
        }
        return false;
    }

    /**
     * @return bool|mixed
     */
    public static function isTaskEnableCoroutine()
    {
        static $isTaskEnableCoroutine;
        if (isset($isTaskEnableCoroutine) && is_bool($isTaskEnableCoroutine)) {
            return $isTaskEnableCoroutine;
        }

        $isTaskEnableCoroutine = false;
        if (version_compare(swoole_version(), '4.4.5', '>')) {
            if (isset(self::$config['setting']['task_enable_coroutine'])) {
                $isTaskEnableCoroutine = filter_var(self::$config['setting']['task_enable_coroutine'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($isTaskEnableCoroutine === null) {
                    $isTaskEnableCoroutine = false;
                }
            }
        }
        return $isTaskEnableCoroutine;
    }

    /**
     * @param string $subClass
     * @param string $parentClass
     * @return bool
     */
    public static function isSubclassOf(string $subClass, string $parentClass)
    {
        if (is_subclass_of($subClass, $parentClass) || trim($subClass, '\\') == trim($parentClass, '\\')) {
            return true;
        }
        return false;
    }

    /**
     * startHandler
     * @return mixed
     * @throws \Exception
     */
    public static function eventHandler()
    {
        $starHandlerClass = isset(self::$config['event_handler']) ? self::$config['event_handler'] : self::$startHandlerClass;
        if (self::isSubclassOf($starHandlerClass, self::$startHandlerClass)) {
            return new $starHandlerClass();
        }
        throw new \Exception("Config item of 'event_handler'=>{$starHandlerClass} must extends " . self::$startHandlerClass . ' class');
    }

    /**
     * requestCount 必须依赖于EnableSysCollector = true，否则设置没有意义，不生效
     * @return bool
     */
    public static function atomicAdd()
    {
        if (self::isEnableSysCollector() && self::isEnablePvCollector()) {
            $atomic = AtomicManager::getInstance()->getAtomicLong('atomic_request_count');
            if (is_object($atomic)) {
                return $atomic->add(1);
            }
        }
        return false;
    }

    /**
     * registerShutdownFunction
     */
    public static function registerShutdownFunction()
    {
        $exceptionClass = self::getExceptionClass();
        register_shutdown_function($exceptionClass . '::fatalError');
    }

    /**
     * registerErrorHandler
     */
    public static function registerErrorHandler()
    {
        $exceptionClass = self::getExceptionClass();
        set_error_handler("{$exceptionClass}::handleError");
    }

    /**
     * beforeRequest
     * @return void
     */
    public static function beforeHandle()
    {
        self::atomicAdd();
    }

    /**
     * endRequest
     * @return void
     */
    public static function endHandler()
    {
    }

}