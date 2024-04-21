<?php
namespace Swoolefy\Cmd;

use Swoolefy\Core\SystemEnv;
use Symfony\Component\Console\Command\Command;
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
            if (!$this->getDefinition()->hasOption($name)) {
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
        if (!defined('APP_NAMES')) {
            fmtPrintError('APP_NAMES Missing defined, please check it');
            exit(0);
        }

        if ($input->getArgument('app_name')) {
            $input->setArgument('app_name', ucfirst($input->getArgument('app_name')));
        }

        $appName = $input->getArgument('app_name');
        $appName = trim($appName);

        defined('APP_NAME') or define('APP_NAME', $appName);
        defined('APP_PATH') or define('APP_PATH', ROOT_PATH.'/'.$appName);
        // env
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

        $cliEnv = SWOOLEFY_DEV;
        // system environment variables
        $env = getenv("SWOOLEFY_CLI_ENV");
        if (in_array($env, SWOOLEFY_ENVS)) {
            $cliEnv = $env;
        }
        defined('SWOOLEFY_ENV') or define('SWOOLEFY_ENV', $cliEnv);
    }

    protected function parseOptions(InputInterface $input, OutputInterface $output)
    {
        $daemon = $input->getOption('daemon');
        $force = $input->getOption('force');
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
        $options = [];
        $argv = new ArgvInput();
        $token = $argv->__toString();
        $items = explode(' ', $token);
        foreach ($items as $item) {
            if (str_starts_with($item, '--') || str_starts_with($item, '-')) {
                $item = trim($item,'-');
                $values = explode('=', $item, 2);
                $options[trim($values[0])] = trim($values[1]);
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
                    if (!isWorkerService()) {
                        fmtPrintError('[' . APP_NAME . ']' . " Server is running, pid={$pid}, pidFile={$pidFile}");
                        exit(0);
                    } else {
                        fmtPrintError('[' . WORKER_SERVICE_NAME . ']' . " is running, pid={$pid}, pidFile={$pidFile}");
                        exit(0);
                    }
                }
            }
        }
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
            $conf['setting']['task_worker_num'] = 1;
            unset($conf['setting']['admin_server'], $conf['setting']['task_worker_num']);
        }
    }

    /**
     * @param $config
     * @return void
     */
    protected function commonHandle(&$config)
    {
        if ($this->isDaemon()) {
            $config['setting']['daemonize'] = true;
        }
        $this->makeDirLogAndPid($config);
        $eventServerFile = APP_PATH . "/Event.php";
        if (!file_exists($eventServerFile)) {
            $search_str = "protocol\\event";
            $replace_str = APP_NAME;
            $file_content_string = file_get_contents(ROOT_PATH . "/src/Stubs/EventHandle.php");
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
     * @param $appName
     * @return mixed|string
     */
    protected function getPidFile($appName)
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
        if (isset($config['setting']['log_file'])) {
            $path = pathinfo($config['setting']['log_file'], PATHINFO_DIRNAME);
            if (!is_dir($path)) {
                mkdir($path, 0777, true);
            }
        }

        if (isset($config['setting']['pid_file'])) {
            $path = pathinfo($config['setting']['pid_file'], PATHINFO_DIRNAME);
            if (!is_dir($path)) {
                mkdir($path, 0777, true);
            }
        }

        if (isWorkerService() && defined('WORKER_PID_FILE_ROOT')) {
            if (!is_dir(WORKER_PID_FILE_ROOT)) {
                mkdir(WORKER_PID_FILE_ROOT, 0777, true);
            }
        }

        if (isCliScript()) {
            $path = pathinfo($config['setting']['pid_file'], PATHINFO_DIRNAME);
            $config['setting']['pid_file'] = parseScriptPidFile($config['setting']['pid_file']);
            register_shutdown_function(function () use ($config) {
                if (is_file($config['setting']['pid_file'])) {
                    @unlink($config['setting']['pid_file']);
                }
            });

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

    protected function isDaemon()
    {
        return isDaemon();
    }

    protected function loadGlobalConf()
    {
        return loadGlobalConf();
    }
}