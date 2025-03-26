<?php
namespace Swoolefy\Cmd;

use Swoolefy\Core\Exec;
use Swoolefy\Core\SystemEnv;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\TableStyle;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class BaseCmd extends Command
{
    /**
     * @var OutputInterface
     */
    protected $consoleStyleIo;

    /**
     * @var array[]
     */
    protected $protocolMap = [
        'http' => [
            'namespace' => 'protocol\\http',
            'server_name' => 'HttpServer'
        ],
        'rpc' => [
            'namespace' => 'protocol\\rpc',
            'server_name' => 'RpcServer'
        ],
        'udp' => [
            'namespace' => 'protocol\\udp',
            'server_name' => 'UdpEventServer'
        ],
        'websocket' => [
            'namespace' => 'protocol\\websocket',
            'server_name' => 'WebsocketEventServer'
        ],
        'mqtt' => [
            'namespace' => 'protocol\\mqtt',
            'server_name' => 'MqttServer'
        ],
    ];

    /**
     * @return void
     */
    protected function configure()
    {
        putenv('COLUMNS=200');
        $this->addArgument('app_name', InputArgument::REQUIRED, 'The app name');
        // 是否守护进程启动
        $this->addOption('daemon', null,InputOption::VALUE_OPTIONAL, 'Daemon model run app', 0);
        // 强制停止
        $this->addOption('force', null,InputOption::VALUE_OPTIONAL, 'Force stop app', 0);

        $options = $this->beforeInputOptions();
        foreach ($options as $name=>$value) {
            if (!$this->getDefinition()->hasOption($name) && !in_array($name, ['help'])) {
                $this->addOption($name,null, InputOption::VALUE_OPTIONAL,'', '');
            }
        }
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->consoleStyleIo = new \Symfony\Component\Console\Style\SymfonyStyle($input, $output);;
        $this->initCheck($input, $output);
        $this->parseConstant($input, $output);
        $this->parseOptions($input, $output);
    }

    protected function parseConstant(InputInterface $input, OutputInterface $output)
    {
        if (!defined('APP_NAME')) {
            fmtPrintError('APP_NAME Missing defined, please check it');
            exit(0);
        }

        if (!defined('APP_META_ARR')) {
            fmtPrintError('APP_META_ARR Missing defined, please check it');
            exit(0);
        }

        if ($input->getArgument('app_name')) {
            $input->setArgument('app_name', APP_NAME);
        }

        defined('APP_PATH') or define('APP_PATH', ROOT_PATH.'/'.APP_NAME);
        defined('SWOOLEFY_DEV') or define('SWOOLEFY_DEV', 'dev');
        defined('SWOOLEFY_TEST') or define('SWOOLEFY_TEST', 'test');
        defined('SWOOLEFY_GRA') or define('SWOOLEFY_GRA', 'gra');
        defined('SWOOLEFY_PRD') or define('SWOOLEFY_PRD', 'prd');
        defined('SWOOLEFY_ENVS') or define('SWOOLEFY_ENVS', [
            SWOOLEFY_DEV,
            SWOOLEFY_TEST,
            SWOOLEFY_GRA,
            SWOOLEFY_PRD,
        ]);

        // system environment variables
        $env = getenv("SWOOLEFY_CLI_ENV");
        if (!in_array($env, SWOOLEFY_ENVS)) {
            fmtPrintError('SWOOLEFY_CLI_ENV not in [dev, test, gra, prd]');
            exit(0);
        }
        defined('SWOOLEFY_ENV') or define('SWOOLEFY_ENV', $env);
    }

    protected function parseOptions(InputInterface $input, OutputInterface $output)
    {
        $daemon = $input->getOption('daemon');
        $force  = $input->getOption('force');
        defined('IS_DAEMON') or define('IS_DAEMON', $daemon);
        defined('IS_FORCE') or define('IS_FORCE', $force);
        $options = $input->getOptions();
        foreach ($options as $optionName=>$value) {
            putenv("{$optionName}={$value}");
        }
        $cliParamsJson = json_encode($options, JSON_UNESCAPED_UNICODE);
        putenv("ENV_CLI_PARAMS={$cliParamsJson}");
    }

    /**
     * @return array
     */
    protected function beforeInputOptions()
    {
        $argv  = new ArgvInput();
        $token = $argv->__toString();
        $items = explode(' ', $token);
        $options = [];
        foreach ($items as $item) {
            if (str_starts_with($item, '--') || str_starts_with($item, '-')) {
                $item = trim($item,'-');
                $values = explode('=', $item, 2);
                $options[trim($values[0])] = trim($values[1] ?? 1);
            }
        }
        return $options;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return void
     */
    protected function initCheck(InputInterface $input, OutputInterface $output)
    {
        if (version_compare(phpversion(), '7.3.0', '<')) {
           fmtPrintError("php version must >= 7.3.0, current php version = " . phpversion());
        }

        if (version_compare(swoole_version(), '4.8.5', '<')) {
           fmtPrintError("the swoole version must >= 4.8.5, current swoole version = " . swoole_version());
        }

        if (function_exists('apc_clear_cache')) {
            apc_clear_cache();
        }

        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
    }

    /**
     * when start, check config
     * @param array $config
     * @return void
     */
    protected function checkRunning(array &$config)
    {
        $this->resetConf($config);
        if (isset($config['setting']['pid_file'])) {
            $pidFile = $config['setting']['pid_file'];
            if (is_file($pidFile)) {
                $pid = file_get_contents($pidFile);
                if (is_numeric($pid) && \Swoole\Process::kill($pid, 0)) {
                    if (!SystemEnv::isWorkerService()) {
                        fmtPrintError('[' . APP_NAME . ']' . " Server is running, pid={$pid}, pidFile={$pidFile}");
                        exit(0);
                    } else {
                        fmtPrintError('[' . WORKER_SERVICE_NAME . '-server]' . " is running, pid={$pid}, pidFile={$pidFile}");
                        exit(0);
                    }
                }
            }
        }
        if ($this->isDaemon()) {
            $config['setting']['daemonize'] = true;
        }

        $this->makeDirLogAndPid($config);
    }

    /**
     * @param $conf
     * @return void
     */
    protected function resetConf(&$conf)
    {
        if (SystemEnv::isWorkerService()) {
            $conf['port'] = WORKER_PORT;
            $conf['setting']['enable_coroutine'] = 0;
            $conf['setting']['reactor_num'] = 1;
            $conf['setting']['worker_num'] = 1;
            unset($conf['setting']['admin_server'], $conf['setting']['task_worker_num']);
        }
    }

    /**
     * @return void
     */
    protected function commonHandleFile()
    {
        $eventServerFile = APP_PATH . "/Event.php";
        if (!file_exists($eventServerFile)) {
            $search_str = "protocol\\event";
            $replace_str = APP_NAME;
            $file_content_string = file_get_contents(ROOT_PATH . "/src/Stubs/event_handle.stub.php");
            $count = 1;
            $file_content_string = str_replace($search_str, $replace_str, $file_content_string, $count);
            file_put_contents($eventServerFile, $file_content_string);
        }

        $autoloaderFile = APP_PATH . "/autoloader.php";
        if (!file_exists($autoloaderFile)) {
            $search_str = "<{APP_NAME}>";
            $replace_str = APP_NAME;
            $file_content_string = file_get_contents(ROOT_PATH . "/autoloader.php");
            $count = 1;
            $file_content_string = str_replace($search_str, $replace_str, $file_content_string, $count);
            file_put_contents($autoloaderFile, $file_content_string);
        }
    }

    /**
     * @param string $appName
     * @return mixed|string
     */
    protected function getPidFile(string $appName)
    {
        $path = APP_PATH . "/Protocol";
        $config = include $path . '/conf.php';
        if (isset($config['setting']['pid_file'])) {
            $pidFile = $config['setting']['pid_file'];
            $path = pathinfo($config['setting']['pid_file'], PATHINFO_DIRNAME);
            if (!is_dir($path)) {
                mkdir($path, 0777, true);
            }
        }
        return $pidFile ?? '';
    }

    /**
     * @param array $config
     * @return void
     */
    protected function makeDirLogAndPid(array &$config)
    {
        // log file
        if (isset($config['setting']['log_file'])) {
            $path = pathinfo($config['setting']['log_file'], PATHINFO_DIRNAME);
            if (!is_dir($path)) {
                mkdir($path, 0777, true);
            }
        }

        // pid file
        if (isset($config['setting']['pid_file'])) {
            $path = pathinfo($config['setting']['pid_file'], PATHINFO_DIRNAME);
            if (!is_dir($path)) {
                mkdir($path, 0777, true);
            }
        }

        if (SystemEnv::isWorkerService() && defined('WORKER_PID_FILE_ROOT')) {
            if (!is_dir(WORKER_PID_FILE_ROOT)) {
                mkdir(WORKER_PID_FILE_ROOT, 0777, true);
            }
        }

        if (isset($config['setting']['pid_file'])) {
            $path = pathinfo($config['setting']['pid_file'], PATHINFO_DIRNAME);
            $config['setting']['pid_file'] = parseScriptPidFile($config['setting']['pid_file']);
            $files = scandir($path);
            foreach ($files as $f) {
                $filePath = $path . '/' . $f;
                if ($f == '.' || $f == '..' || is_dir($filePath)) {
                    continue;
                }
                $pid = file_get_contents($filePath);
                if (is_numeric($pid)) {
                    if (!\Swoole\Process::kill($pid, 0)) {
                        @unlink($filePath);
                    }
                }
            }
        }

        if (!isset($config['app_conf'])) {
            fmtPrintError(APP_NAME . '/Protocol/conf.php must include app_conf file and set app_conf');
            exit(0);
        }
    }

    /**
     * @param string $appName
     * @param string $pidFile
     * @return void
     */
    protected function serverStatus(string $appName, string $pidFile)
    {
        if (!is_file($pidFile)) {
            fmtPrintError("Pid file={$pidFile} is not exist, please check server weather is running");
            return;
        }

        $pid = intval(file_get_contents($pidFile));
        if (!\Swoole\Process::kill($pid, 0)) {
            fmtPrintError("Server Maybe Shutdown, You can use 'ps -ef | grep php-swoolefy' ");
            return;
        }

        if (defined('SERVER_START_LOG_JSON_FILE') && is_file(SERVER_START_LOG_JSON_FILE)) {
            $startContent = file_get_contents(SERVER_START_LOG_JSON_FILE);
            $startContent = json_decode($startContent, true);
            if (isset($startContent['start_time'])) {
                $startTime = $startContent['start_time'] ?? '';
            }
        }

        SystemEnv::formatPrintStartLog($startTime ?? '');

        $exec = (new Exec())->run('pgrep -P ' . $pid);
        $output = $exec->getOutput();
        $managerProcessId = -1;
        $workerProcessIds = [];
        if (isset($output[0])) {
            $managerProcessId = current($output);
            $workerProcessIds = (new Exec())->run('pgrep -P ' . $managerProcessId)->getOutput();
        }

        $output = new \Symfony\Component\Console\Output\ConsoleOutput();
        $table  = new \Symfony\Component\Console\Helper\Table($output);
        $table->setHeaders(['进程名称', '进程ID','父进程ID', '进程状态', '启动时间']);
        if (!empty($startTime)) {
            $table->setRows(array(
                array('master process', $pid,'--','running', $startTime),
                array('manager process', $managerProcessId, $pid, 'running', $startTime)
            ));
        }else {
            $table->setRows(array(
                array('master process', $pid,'--','running','--'),
                array('manager process', $managerProcessId, $pid, 'running','--')
            ));
        }

        foreach ($workerProcessIds as $id=>$processId) {
            $table->addRow(array("worker process-{$id}", $processId, $managerProcessId, 'running', '--'));
        }

        $tableStyle = new TableStyle();
        $tableStyle->setCellRowFormat('<info>%s</info>');
        $table->setStyle($tableStyle)->render();
    }

    protected function isDaemon()
    {
        return isDaemon();
    }

    protected function loadGlobalConf()
    {
        return loadGlobalConf();
    }

    /**
     * @param string $msg
     * @return void
     */
    protected function writeLog(string $msg)
    {
        if (defined('WORKER_CTL_LOG_FILE')) {
            if (defined('MAX_LOG_FILE_SIZE')) {
                $maxLogFileSize = constant('MAX_LOG_FILE_SIZE');
            } else {
                $maxLogFileSize = 5 * 1024 * 1024;
            }

            if (is_file(WORKER_CTL_LOG_FILE) && filesize(WORKER_CTL_LOG_FILE) > $maxLogFileSize) {
                unlink(WORKER_CTL_LOG_FILE);
            }

            if (!is_dir(dirname(WORKER_CTL_LOG_FILE))) {
                mkdir(dirname(WORKER_CTL_LOG_FILE), 0777, true);
            }

            $logFd = fopen(WORKER_CTL_LOG_FILE, 'a+');
            $date  = date("Y-m-d H:i:s");
            $writeMsg = "【{$date}】" . $msg . PHP_EOL;
            fwrite($logFd, $writeMsg);
            fclose($logFd);
        }
    }
}