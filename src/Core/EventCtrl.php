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

use Swoole\Server;
use Swoolefy\Http\Route;
use Swoolefy\Core\Log\LogManager;
use Swoolefy\Core\Coroutine\CoroutinePools;
use Swoolefy\Core\Log\Formatter\LineFormatter;
use Swoolefy\Core\Process\ProcessManager;
use Swoolefy\Core\ProcessPools\PoolsManager;

class EventCtrl implements EventCtrlInterface
{
    /**
     * init run in before start method
     * @return void
     */
    public function init()
    {
        if (!SystemEnv::isWorkerService()) {
            if (BaseServer::isHttpApp()) {
                Route::loadRouteFile();
            }else if (BaseServer::isWebsocketApp() || BaseServer::isUdpApp()) {
                ServiceDispatch::loadRouteFile();
            }
        }
        static::onInit();
        $this->registerSqlLogger();
        $this->registerGuzzleCurlLogger();

        if(!$this->isWorkerService()) {
            if (BaseServer::isEnableSysCollector()) {
                ProcessManager::getInstance()->addProcess('swoolefy_system_collector', \Swoolefy\Core\SysCollector\SysProcess::class);
            }
            if (BaseServer::isEnableReload()) {
                ProcessManager::getInstance()->addProcess('swoolefy_system_reload', \Swoolefy\AutoReload\ReloadProcess::class);
            }
        }else {
            static::onWorkerServiceInit();
            $this->boostrapWorkerInit();
        }
        static::eachStartInfo();
    }

    /**
     * @return void
     */
    protected function boostrapWorkerInit()
    {
        if (!defined('WORKER_SERVICE_NAME')) {
            write('Missing Defined Constant `WORKER_SERVICE_NAME`');
            exit(0);
        }

        if (!defined('PROCESS_CLASS')) {
            write('Missing Defined Constant `PROCESS_CLASS`');
            exit(0);
        }

        $processClassMap = PROCESS_CLASS;
        if (SystemEnv::isDaemonService() || SystemEnv::isCronService()) {
            $processClass = $processClassMap[APP_NAME];
            ProcessManager::getInstance()->addProcess(WORKER_SERVICE_NAME, $processClass, true,  [],null, false);
        }else if (SystemEnv::isScriptService()) {
            $class = \Swoolefy\Script\MainCliScript::parseClass();
            if(empty($class)) {
                write('Not found CliScript Class');
                exit(0);
            }
            ProcessManager::getInstance()->addProcess(WORKER_SERVICE_NAME, $class);
        }else {
            write('Error Service Type');
            exit(0);
        }
    }

    /**
     * 注册debug模式下sql日志打印
     *
     * @return void
     */
    protected function registerSqlLogger()
    {
        LogManager::getInstance()->registerLoggerByClosure(function ($name) {
            $logger = new \Swoolefy\Util\Log($name);
            $logger->setChannel('application');
            $formatter = new LineFormatter("%message%\n");
            $logger->setFormatter($formatter);
            $baseSqlPath = pathinfo(LOG_PATH)['dirname'].DIRECTORY_SEPARATOR.'Sql';
            if (!is_dir($baseSqlPath)) {
                mkdir($baseSqlPath,0777);
            }
            if (SystemEnv::isDaemonService()) {
                $sqlLogName = 'sql_daemon.log';
            }else if (SystemEnv::isCronService()) {
                $sqlLogName = 'sql_cron.log';
            }else if (SystemEnv::isScriptService()) {
                $sqlLogName = 'sql_script.log';
            }else {
                $sqlLogName = 'sql.log';
            }
            $sqlFilePath = $baseSqlPath.DIRECTORY_SEPARATOR.$sqlLogName;
            $logger->setLogFilePath($sqlFilePath);
            return $logger;
        }, 'sql_log');
    }

    /**
     * 注册GuzzleCurlLog
     *
     * @return void
     */
    protected function registerGuzzleCurlLogger()
    {
        LogManager::getInstance()->registerLoggerByClosure(function ($name) {
            $logger = new \Swoolefy\Util\Log($name);
            $logger->setChannel('application');
            $formatter = new LineFormatter("%message%\n");
            $logger->setFormatter($formatter);
            $baseSqlPath = pathinfo(LOG_PATH)['dirname'].DIRECTORY_SEPARATOR.'GuzzleCurl';
            if (!is_dir($baseSqlPath)) {
                mkdir($baseSqlPath,0777);
            }
            $sqlFilePath = $baseSqlPath.DIRECTORY_SEPARATOR.'guzzle_curl.log';
            $logger->setLogFilePath($sqlFilePath);
            return $logger;
        }, 'guzzle_curl_log');
    }

    /**
     * @return bool
     */
    protected function isWorkerService(): bool
    {
        return SystemEnv::isWorkerService();
    }

    /**
     * onStart
     * @param Server $server
     * @return void
     */
    public function start($server)
    {
        static::onStart($server);
    }

    /**
     * onManagerStart
     * @param Server $server
     * @return void
     */
    public function managerStart($server)
    {
        static::onManagerStart($server);
    }

    /**
     * onWorkerStart
     * @param Server $server
     * @return void
     */
    public function workerStart($server, $worker_id)
    {
        if(!SystemEnv::isWorkerService()) {
            $this->registerComponentPools();
        }
        static::onWorkerStart($server, $worker_id);
    }

    /**
     * onWorkerStop
     * @param Server $server
     * @param int $worker_id
     * @return void
     */
    public function workerStop($server, $worker_id)
    {
        static::onWorkerStop($server, $worker_id);
    }

    /**
     * workerError
     * @param Server $server
     * @param int $worker_id
     * @param int $worker_pid
     * @param mixed $exit_code
     * @param bool $signal
     * @return void
     */
    public function workerError($server, $worker_id, $worker_pid, $exit_code, $signal)
    {
        static::onWorkerError($server, $worker_id, $worker_pid, $exit_code, $signal);
    }

    /**
     * workerExit
     * @param Server $server
     * @param int $worker_id
     * @return void
     */
    public function workerExit($server, $worker_id)
    {
        static::onWorkerExit($server, $worker_id);
    }

    /**
     * onManagerStop
     * @param Server $server
     * @return void
     */
    public function managerStop($server)
    {
        static::onManagerStop($server);
    }

    /**
     * @param array $appConf
     * @return bool
     */
    protected function isEnableComponentPools(array $appConf): bool
    {
        if (isset($appConf['enable_component_pools']) && is_array($appConf['enable_component_pools']) && !empty($appConf['enable_component_pools'])) {
            return true;
        }
        return false;
    }

    /**
     * 在workerStart可以创建一个协程池Channel
     * 'enable_component_pools' => [
     *      'redis' => [
     *              'max_pool_num' => 5,
     *              'max_push_timeout' => 2,
     *              'max_pop_timeout' => 1,
     *              'max_life_timeout' => 10
     *      ]
     * ],
     *
     * @throws mixed
     */
    protected function registerComponentPools()
    {
        $appConf = BaseServer::getAppConf();
        if ($this->isEnableComponentPools($appConf)) {
            $components = array_keys($appConf['components']);
            foreach ($appConf['enable_component_pools'] as $poolName => $componentPoolConfig) {
                if (!in_array($poolName, $components)) {
                    continue;
                }

                $callable = $appConf['components'][$poolName];
                CoroutinePools::getInstance()->addPool($poolName, $componentPoolConfig, $callable);
            }

            \Swoole\Timer::tick(60*1000, function () {
                $this->clearComponentPools();
            });
        }
    }

    /**
     * @return void
     */
    protected function clearComponentPools()
    {
        $appConf = BaseServer::getAppConf();
        if ($this->isEnableComponentPools($appConf)) {
            $components = array_keys($appConf['components']);
            foreach ($appConf['enable_component_pools'] as $poolName => $componentPoolConfig) {
                if (!in_array($poolName, $components)) {
                    continue;
                }
                if (isset($componentPoolConfig['enable_tick_clear']) && !empty($componentPoolConfig['enable_tick_clear'])) {
                    CoroutinePools::getInstance()->getPool($poolName)->clearPool();
                }
            }
        }
    }

    /**
     * eachStartInfo
     */
    protected function eachStartInfo()
    {
        $protocol = BaseServer::getServiceProtocol();
        switch ($protocol) {
            case SWOOLEFY_HTTP :
                $mainServer = 'HttpServer';
                break;
            case SWOOLEFY_WEBSOCKET :
                $mainServer = 'WebsockServer';
                break;
            case SWOOLEFY_TCP :
                $mainServer = 'RpcServer';
                break;
            case SWOOLEFY_UDP :
                $mainServer = 'UdpServer';
                break;
            case SWOOLEFY_MQTT :
                $mainServer = 'MqttServer';
                break;
            default:
                $mainServer = 'HttpServer';
        }

        if(isWorkerService()) {
            $mainName = 'main worker';
            $mainServer = "【".WORKER_SERVICE_NAME."】";
        }else {
            $mainName = 'main server';
        }

        $conf                    = Swfy::getConf();
        $daemonize               = isset($conf['setting']['daemonize']) ? $conf['setting']['daemonize'] : false;
        $listenHost              = isset($conf['host']) ? $conf['host'] : '127.0.0.1';
        $listenPort              = isset($conf['port']) ? $conf['port'] : null;
        $workerNum               = isset($conf['setting']['worker_num']) ? $conf['setting']['worker_num'] : 1;
        $taskWorkerNum           = isset($conf['setting']['task_worker_num']) ? $conf['setting']['task_worker_num'] : 0;
        $swooleVersion           = swoole_version();
        $phpVersion              = phpversion();
        $swoolefyVersion         = SWOOLEFY_VERSION;
        $swoolefyEnv             = defined('SWOOLEFY_ENV') ? SWOOLEFY_ENV : null;
        $cpuNum                  = swoole_cpu_num();
        $ipList                  = json_encode(swoole_get_local_ip());
        $processListInfo         = array_values(ProcessManager::getInstance()->getProcessListInfo());
        $processListInfoStr      = json_encode($processListInfo, JSON_UNESCAPED_UNICODE);
        $poolsProcessListInfo    = array_values(PoolsManager::getInstance()->getProcessListInfo());
        $poolsProcessListInfoStr = json_encode($poolsProcessListInfo, JSON_UNESCAPED_UNICODE);
        $hostname                = gethostname();

        $this->each("Main Info: \n", 'light_green');
        $this->each(str_repeat('-', 50), 'light_green');
        $this->each("
            {$mainName}         {$mainServer}
            swoolefy envirment  {$swoolefyEnv}
            daemonize           {$daemonize}
            listen address      {$listenHost}
            listen port         {$listenPort}
            worker num          {$workerNum}
            task worker num     {$taskWorkerNum}
            cpu num             {$cpuNum}
            swoole version      {$swooleVersion}
            php version         {$phpVersion}
            swoolefy version    {$swoolefyVersion}
            ip_list             {$ipList}
            hostname            {$hostname}
            tips                执行 php swoolefy help 可以查看更多信息
", 'light_green');
        $this->each(str_repeat('-', 50) . "\n", 'light_green');

        if(isDaemonService()) {
            $this->each("Daemon Worker Info: \n", 'light_green');
        }else if (isCronService()) {
            $this->each("Cron Worker Info: \n", 'light_green');
        }else if(isCliScript()) {
            $this->each("Cli Script Start: \n", 'light_green');
        }
    }

    /**
     * _each
     * @param string $msg
     * @param string $foreground
     * @param string $background
     */
    protected function each(string $msg, string $foreground = "red", string $background = "black")
    {
        _each($msg, $foreground, $background);
    }
} 