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
use Swoolefy\Core\Log\Formatter\JsonFormatter;
use Swoolefy\Http\Route;
use Swoolefy\Core\Log\LogManager;
use Swoolefy\Core\Coroutine\CoroutinePools;
use Swoolefy\Core\Log\Formatter\LineFormatter;
use Swoolefy\Core\Process\ProcessManager;

class EventCtrl implements EventCtrlInterface
{
    /**
     * init run in before start method
     * @return void
     */
    public function init()
    {
        if (SystemEnv::isScriptService()) {
            \Swoolefy\Script\MainCliScript::parseClass();
        }
        // log register
        if (SystemEnv::isDaemonService() || SystemEnv::isCronService() || SystemEnv::cronScheduleScriptModel()) {
            \Swoolefy\Worker\AbstractWorkerProcess::registerLogComponents();
        }else {
            SystemEnv::registerLogComponents();
        }

        if (!SystemEnv::isWorkerService()) {
            if (BaseServer::isHttpApp()) {
                Route::loadRouteFile();
            }else if (BaseServer::isWebsocketApp() || BaseServer::isUdpApp()) {
                ServiceDispatch::loadRouteFile();
            }
        }

        static::onInit();
        $this->registerSqlLogger();
        $this->registerCrontabLogger();
        $this->registerGuzzleCurlLogger();

        if(!SystemEnv::isWorkerService()) {
            if (BaseServer::isEnableSysCollector()) {
                ProcessManager::getInstance()->addProcess('swoolefy_system_collector', \Swoolefy\Core\SysCollector\SysProcess::class);
            }
            if (BaseServer::isEnableReload()) {
                ProcessManager::getInstance()->addProcess('swoolefy_system_reload', \Swoolefy\AutoReload\ReloadProcess::class);
            }

            $this->registerStartLog();

        }else {
            static::onWorkerServiceInit();
            $this->boostrapWorkerInit();
        }
        static::printStartInfo();
    }

    /**
     * @return void
     */
    protected function boostrapWorkerInit()
    {
        if (!defined('WORKER_SERVICE_NAME')) {
            fmtPrintError('Missing Defined Constant `WORKER_SERVICE_NAME`');
            exit(0);
        }

        if (!defined('PROCESS_CLASS')) {
            fmtPrintError('Missing Defined Constant `PROCESS_CLASS`');
            exit(0);
        }

        $processClassMap = PROCESS_CLASS;
        if (SystemEnv::isDaemonService() || SystemEnv::isCronService()) {
            $processClass = $processClassMap[APP_NAME];
            ProcessManager::getInstance()->addProcess(WORKER_SERVICE_NAME, $processClass, true,  [],null, false);
        }else if (SystemEnv::isScriptService()) {
            try {
                $class = \Swoolefy\Script\MainCliScript::parseClass();
            }catch (\Throwable $throwable) {
                fmtPrintError($throwable->getMessage());
                exit(0);
            }
            ProcessManager::getInstance()->addProcess(WORKER_SERVICE_NAME, $class);
        }else {
            fmtPrintError('Error Service Type');
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
            $logger->setRotateDay(2);
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
                $sqlLogName = 'sql_cli.log';
            }
            $sqlFilePath = $baseSqlPath.DIRECTORY_SEPARATOR.$sqlLogName;
            $logger->setLogFilePath($sqlFilePath);
            return $logger;
        }, LogManager::SQL_LOG);
    }

    /**
     * 注册cron服务下的计划任务crontab调度日志
     *
     * @return void
     */
    protected function registerCrontabLogger()
    {
        if (!SystemEnv::isCronService()) {
            return;
        }

        LogManager::getInstance()->registerLoggerByClosure(function ($name) {
            $logger = new \Swoolefy\Util\Log($name);
            $logger->setRotateDay(2);
            $logger->setChannel('application');
            $baseCronPath = pathinfo(LOG_PATH)['dirname'].DIRECTORY_SEPARATOR.'Crontab';
            if (!is_dir($baseCronPath)) {
                mkdir($baseCronPath,0777);
            }
            $cronLogName = 'cron.log';
            $cronFilePath = $baseCronPath.DIRECTORY_SEPARATOR.$cronLogName;
            $logger->setLogFilePath($cronFilePath);
            return $logger;
        }, LogManager::CRON_LOG);
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
            $logger->setRotateDay(2);
            $logger->setChannel('application');
            $formatter = new LineFormatter("%message%\n");
            $logger->setFormatter($formatter);
            $baseSqlPath = pathinfo(LOG_PATH)['dirname'].DIRECTORY_SEPARATOR.'GuzzleCurl';
            if (!is_dir($baseSqlPath)) {
                mkdir($baseSqlPath,0777);
            }

            if (SystemEnv::isDaemonService()) {
                $curlFilePath = $baseSqlPath.DIRECTORY_SEPARATOR.'curl_daemon.log';
            }else if (SystemEnv::isCronService()) {
                $curlFilePath = $baseSqlPath.DIRECTORY_SEPARATOR.'curl_cron.log';
            }else if (SystemEnv::isScriptService()) {
                $curlFilePath = $baseSqlPath.DIRECTORY_SEPARATOR.'curl_script.log';
            }else {
                $curlFilePath = $baseSqlPath.DIRECTORY_SEPARATOR.'curl_cli.log';
            }

            $logger->setLogFilePath($curlFilePath);
            return $logger;
        }, LogManager::GUZZLE_CURL_LOG);
    }

    /**
     * @return void
     */
    protected function registerStartLog()
    {
        if (defined('SERVER_START_LOG')) {
            $pathDir = pathinfo(SERVER_START_LOG);
            if (!is_dir($pathDir['dirname'])) {
                mkdir($pathDir['dirname'], 0777, true);
            }
            $startLog = ['start_time' => date('Y-m-d H:i:s')];
            file_put_contents(SERVER_START_LOG, json_encode($startLog));
            chmod(SERVER_START_LOG, 0777);
        }
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
        $this->registerGcMemCaches();
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
        if (isset($appConf['component_pools']) && is_array($appConf['component_pools']) && !empty($appConf['component_pools'])) {
            return true;
        }
        return false;
    }

    /**
     * 在workerStart可以创建一个协程池Channel
     * 'component_pools' => [
     *      'redis' => [
     *              'max_pool_num' => 5,
     *              'max_push_timeout' => 2,
     *              'max_pop_timeout' => 1,
     *              'max_life_timeout' => 10
     *              'enable_tick_clear_pool' => 1 //max_pool_num 足够大时需要设置这个定时回收
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
            foreach ($appConf['component_pools'] as $poolName => $componentPoolConfig) {
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
            foreach ($appConf['component_pools'] as $poolName => $componentPoolConfig) {
                if (!in_array($poolName, $components)) {
                    continue;
                }
                if (isset($componentPoolConfig['enable_tick_clear_pool']) && !empty($componentPoolConfig['enable_tick_clear_pool'])) {
                    CoroutinePools::getInstance()->getPool($poolName)->clearPool();
                }
            }
        }
    }

    /**
     * @return void
     */
    protected function registerGcMemCaches()
    {
        $conf =BaseServer::getConf();
        if (isset($conf['gc_mem_cache_enable']) && !empty($conf['gc_mem_cache_enable'])) {
            $time = $conf['gc_mem_cache_tick_time'] ?? 30;
            \Swoole\Timer::tick($time * 1000, function () {
                gc_mem_caches();
            });
        }
    }

    /**
     * eachStartInfo
     */
    protected function printStartInfo()
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

        if(SystemEnv::isWorkerService()) {
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
        //$processListInfo         = array_values(ProcessManager::getInstance()->getProcessListInfo());
        //$processListInfoStr      = json_encode($processListInfo, JSON_UNESCAPED_UNICODE);
        //$poolsProcessListInfo    = array_values(PoolsManager::getInstance()->getProcessListInfo());
        //$poolsProcessListInfoStr = json_encode($poolsProcessListInfo, JSON_UNESCAPED_UNICODE);
        $hostname                = gethostname();

        $consoleStyleIo = initConsoleStyleIo();
        $line = str_repeat('-', 50);
        $consoleStyleIo->write("<info>$line</info>", true);
        $consoleStyleIo->write("<info>Main Info:</info>");
        $consoleStyleIo->write("<info>
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
</info>");

        $consoleStyleIo->write("<info>$line</info>", true);
        if(SystemEnv::isDaemonService()) {
            $consoleStyleIo->write("<info>Daemon Worker Info:\n</info>",true);
        }else if (SystemEnv::isCronService()) {
            $consoleStyleIo->write("<info>Cron Worker Info:</info>", true);
        }else if(SystemEnv::isScriptService()) {
            $consoleStyleIo->write("<info>Cli Script Start:</info>",true);
        }
    }
} 