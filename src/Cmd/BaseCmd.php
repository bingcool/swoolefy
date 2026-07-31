<?php

declare(strict_types=1);

namespace Swoolefy\Cmd;

use Swoolefy\Cmd\DTO\CmdContext;
use Swoolefy\Cmd\Infrastructure\CmdInitException;
use Swoolefy\Cmd\Infrastructure\CmdLogWriter;
use Swoolefy\Cmd\Infrastructure\PidFileManager;
use Swoolefy\Cmd\Infrastructure\ServerStatusRenderer;
use Swoolefy\Core\SystemEnv;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * CLI 命令基类。
 *
 * 职责：
 * - 环境初始化（常量定义、选项解析、版本检查）
 * - 构建命令上下文 CmdContext
 * - 提供通用工具方法（getPidFile、writeLog、serverStatus 等）
 *
 * 瘦身原则：
 * - 进程管理、PID 操作、日志写入、状态渲染等职责已委托给 Infrastructure 层
 * - 本类仅保留 initialize 流程编排和向后兼容的委托方法
 *
 * 初始化异常处理：
 * - parseConstant / initCheck 中的校验失败抛出 CmdInitException
 * - 在 initialize() 中统一 catch，设置 $initError
 * - 子类 execute() 开头通过 hasInitError() 检查，返回 Command::FAILURE
 */
class BaseCmd extends Command
{
    /** CLI 参数名：应用名称 */
    const APP_NAME = 'app_name';

    /** CLI 选项名：守护进程模式 */
    const DAEMON = 'daemon';

    /** CLI 选项名：强制执行（跳过确认） */
    const FORCE = 'force';

    /** CLI 选项名：启动模式（restart 等） */
    const START_MODEL = 'start_model';

    /** 协议常量 */
    const HTTP_PROTOCOL = 'http';
    const UDP_PROTOCOL = 'udp';
    const WEBSOCKET_PROTOCOL = 'websocket';
    const MQTT_PROTOCOL = 'mqtt';
    const RPC_PROTOCOL = 'rpc';

    /** @var SymfonyStyle 控制台 IO 样式 */
    protected SymfonyStyle $consoleStyleIo;

    /** @var CmdContext|null 命令上下文，initialize() 后构建 */
    private ?CmdContext $cmdContext = null;

    /** @var \Throwable|null 初始化阶段异常 */
    private ?\Throwable $initError = null;

    /**
     * 协议到 Server 类名的映射表。
     *
     * 各协议的 namespace 和 server_name 在此集中定义，
     * StartCmd 和 CreateCmd 均依赖此映射。
     *
     * @var array<string, array{namespace: string, server_name: string}>
     */
    protected array $protocolMap = [
        self::HTTP_PROTOCOL => [
            'namespace' => 'protocol\\http',
            'server_name' => 'HttpServer',
        ],
        self::RPC_PROTOCOL => [
            'namespace' => 'protocol\\rpc',
            'server_name' => 'RpcServer',
        ],
        self::UDP_PROTOCOL => [
            'namespace' => 'protocol\\udp',
            'server_name' => 'UdpEventServer',
        ],
        self::WEBSOCKET_PROTOCOL => [
            'namespace' => 'protocol\\websocket',
            'server_name' => 'WebsocketEventServer',
        ],
        self::MQTT_PROTOCOL => [
            'namespace' => 'protocol\\mqtt',
            'server_name' => 'MqttServer',
        ],
    ];

    /**
     * 注册 CLI 参数和选项。
     *
     * 所有命令共享的参数/选项在此定义：
     * - app_name：应用名称（必选位置参数）
     * - --daemon：守护进程模式
     * - --force：强制执行（跳过交互确认）
     * - 动态选项：通过 beforeInputOptions() 从命令行自动发现
     */
    protected function configure()
    {
        putenv('COLUMNS=200');
        $this->addArgument(self::APP_NAME, InputArgument::REQUIRED, 'The app name');
        $this->addOption(self::DAEMON, null, InputOption::VALUE_OPTIONAL, 'Daemon model run app', 0);
        $this->addOption(self::FORCE, null, InputOption::VALUE_OPTIONAL, 'Force stop app', 0);

        // 注册动态选项（从命令行自动发现 --xxx=yyy 格式的参数）
        $options = $this->beforeInputOptions();
        foreach ($options as $name => $value) {
            if (!$this->getDefinition()->hasOption($name) && !in_array($name, ['help'])) {
                $this->addOption($name, null, InputOption::VALUE_OPTIONAL, '', '');
            }
        }
    }

    /**
     * 命令初始化流程编排。
     *
     * 在 execute() 之前自动调用，依次执行：
     * 1. 版本和环境检查（initCheck）
     * 2. 全局常量定义（parseConstant）
     * 3. CLI 选项解析（parseOptions）
     * 4. 构建命令上下文（buildContext）
     *
     * 任一步骤失败抛出 CmdInitException，统一 catch 后设置 initError。
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->consoleStyleIo = new SymfonyStyle($input, $output);

        try {
            $this->initCheck($input, $output);
            $this->parseConstant($input, $output);
            $this->parseOptions($input, $output);
            $this->buildContext($input);
        } catch (CmdInitException $e) {
            $this->initError = $e;
            fmtPrintError($e->getMessage());
        } catch (\Throwable $e) {
            // 捕获 Protocol/conf.php 等 include 中抛出的非 CmdInitException 异常
            // （如 SystemException、RuntimeException 等），避免命令无声退出
            $this->initError = $e;
            fmtPrintError("初始化失败: " . $e->getMessage());
        }
    }

    /**
     * 检查是否存在初始化异常。
     *
     * 子类 execute() 开头应调用此方法，若返回 true 则直接 return Command::FAILURE。
     */
    protected function hasInitError(): bool
    {
        return $this->initError !== null;
    }

    /**
     * 获取命令上下文。
     *
     * 包含当前命令执行所需的全部应用信息（appName、pidFile、port、config 等）。
     * 仅在 initialize() 完成后才能调用。
     *
     * @throws \LogicException 若上下文未初始化
     */
    protected function context(): CmdContext
    {
        if ($this->cmdContext === null) {
            throw new \LogicException('CmdContext not initialized');
        }
        return $this->cmdContext;
    }

    /**
     * 版本和环境检查。
     *
     * 要求：PHP >= 8.1.0（使用 #[AsCommand] Attribute 和 enum），
     * Swoole >= 5.1.0（使用 Swoole 5.x 特性）。
     *
     * @throws CmdInitException 版本不满足时抛出
     */
    protected function initCheck(InputInterface $input, OutputInterface $output)
    {
        if (version_compare(phpversion(), '8.1.0', '<')) {
            throw new CmdInitException("PHP version must >= 8.1.0, current: " . phpversion());
        }

        if (version_compare(swoole_version(), '5.1.0', '<')) {
            throw new CmdInitException("Swoole version must >= 5.1.0, current: " . swoole_version());
        }

        // 清除 OPcache 缓存，确保加载最新代码
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
    }

    /**
     * 定义全局常量。
     *
     * 核心常量：APP_NAME、APP_META_ARR、APP_PATH、SWOOLEFY_ENV 等。
     * 这些常量在框架入口文件（cli.php/daemon.php/cron.php/script.php）中已定义，
     * 此处做防御性检查和环境校验。
     *
     * @throws CmdInitException 缺少必要常量或环境变量无效时抛出
     */
    protected function parseConstant(InputInterface $input, OutputInterface $output)
    {
        if (!defined('APP_NAME')) {
            throw new CmdInitException('APP_NAME Missing defined, please check it');
        }

        if (!defined('APP_META_ARR')) {
            throw new CmdInitException('APP_META_ARR Missing defined, please check it');
        }

        if ($input->getArgument(self::APP_NAME)) {
            $input->setArgument(self::APP_NAME, APP_NAME);
        }

        defined('APP_PATH') or define('APP_PATH', ROOT_PATH . '/' . APP_NAME);
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

        // 校验环境变量
        $env = getenv("SWOOLEFY_CLI_ENV");
        if (!in_array($env, SWOOLEFY_ENVS)) {
            throw new CmdInitException('SWOOLEFY_CLI_ENV not in [dev, test, gra, prd]');
        }
        defined('SWOOLEFY_ENV') or define('SWOOLEFY_ENV', $env);
    }

    /**
     * 解析 CLI 选项并设置环境变量。
     *
     * 将 --daemon=1 --force=1 等选项写入 getenv()，
     * 供框架其他组件（如 SystemEnv::isDaemon()）读取。
     */
    protected function parseOptions(InputInterface $input, OutputInterface $output)
    {
        $daemon = $input->getOption(self::DAEMON);
        $force = $input->getOption(self::FORCE);
        defined('IS_DAEMON') or define('IS_DAEMON', $daemon);
        defined('IS_FORCE') or define('IS_FORCE', $force);

        $options = $input->getOptions();
        foreach ($options as $optionName => $value) {
            putenv("{$optionName}={$value}");
        }
        $cliParamsJson = json_encode($options, JSON_UNESCAPED_UNICODE);
        putenv("ENV_CLI_PARAMS={$cliParamsJson}");
    }

    /**
     * 从命令行参数中自动发现动态选项。
     *
     * 解析 --xxx=yyy 格式的参数，用于 configure() 中注册未预定义的选项。
     * 这使得各入口脚本（daemon.php 等）可以透传自定义选项。
     *
     * @return array<string, string> 选项名到值的映射
     */
    protected function beforeInputOptions(): array
    {
        $argv = new ArgvInput();
        $token = $argv->__toString();
        $items = explode(' ', $token);
        $options = [];
        foreach ($items as $item) {
            if (str_starts_with($item, '--') || str_starts_with($item, '-')) {
                $item = trim($item, '-');
                $values = explode('=', $item, 2);
                $options[trim($values[0])] = trim((string) ($values[1] ?? 1));
            }
        }
        return $options;
    }

    /**
     * 构建命令上下文 CmdContext。
     *
     * 从 APP_META_ARR 中获取应用元信息，结合 Protocol/conf.php 配置，
     * 构建包含 appName、pidFile、port、config 等信息的不可变上下文对象。
     *
     * @throws CmdInitException 应用名称不在 APP_META_ARR 中时抛出
     */
    private function buildContext(InputInterface $input): void
    {
        $appName = $input->getArgument(self::APP_NAME);
        $appMeta = APP_META_ARR[$appName] ?? null;
        if (!$appMeta) {
            throw new CmdInitException("App '{$appName}' not found in APP_META_ARR");
        }
        $this->cmdContext = CmdContext::fromConfig($appName, $appMeta);
    }

    /**
     * 启动前检查：确认服务未运行，创建必要的目录。
     *
     * 检查逻辑：
     * 1. 调用 resetConf() 修正配置（WorkerService 特殊处理）
     * 2. 若启用 SWOOLEFY_ZERO_DOWNTIME，跳过运行检查（SO_REUSEPORT 场景）
     * 3. 检查 PID 文件，若服务已运行则抛出异常
     * 4. 调用 makeDirLogAndPid() 创建目录
     *
     * @param array $config Protocol/conf.php 配置（引用传递，可能被修改）
     * @throws CmdInitException 服务已运行时抛出
     */
    protected function checkRunning(array &$config)
    {
        $this->resetConf($config);

        // SO_REUSEPORT 零停机：允许在旧 Master 仍运行时拉起新 Master
        if ('1' === getenv('SWOOLEFY_ZERO_DOWNTIME')) {
            if ($this->isDaemon()) {
                $config['setting']['daemonize'] = true;
            }
            $this->makeDirLogAndPid($config);
            return;
        }

        if (isset($config['setting']['pid_file'])) {
            $pidFile = $config['setting']['pid_file'];
            if (is_file($pidFile)) {
                $pid = file_get_contents($pidFile);
                if (is_numeric($pid) && \Swoole\Process::kill((int) $pid, 0)) {
                    if (!SystemEnv::isWorkerService()) {
                        throw new CmdInitException('[' . APP_NAME . ']' . " Server is running, pid={$pid}, pidFile={$pidFile}");
                    } else {
                        throw new CmdInitException('[' . WORKER_SERVICE_NAME . '-server]' . " is running, pid={$pid}, pidFile={$pidFile}");
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
     * 修正 Protocol/conf.php 配置。
     *
     * WorkerService 模式下的特殊处理：
     * - 强制设置 enable_coroutine=0、reactor_num=1、worker_num=1
     * - 移除 admin_server 和 task_worker_num
     */
    protected function resetConf(&$conf)
    {
        if (SystemEnv::isWorkerService()) {
            $conf['port'] = WORKER_PORT;
            $conf['setting']['enable_coroutine'] = 0;
            $conf['setting']['reactor_num'] = 1;
            $conf['setting']['worker_num'] = 1;
            unset($conf['setting']['admin_server'], $conf['setting']['task_worker_num']);
        } else {
            $conf['port'] = WORKER_PORT;
        }
    }

    /**
     * 创建 Event.php 和 Autoloader.php（如果不存在）。
     *
     * 仅在 create 命令和应用首次启动时调用，
     * 从 Stubs 模板生成应用级事件处理和自动加载文件。
     */
    /**
     * @param string|null $appPathDir 目标应用目录；null 时使用 APP_PATH（兼容启动路径）
     */
    protected function commonHandleFile(?string $appPathDir = null)
    {
        $appPathDir = $appPathDir ?? APP_PATH;
        $eventServerFile = $appPathDir . "/Event.php";
        if (!file_exists($eventServerFile)) {
            $search_str = "protocol\\event";
            $replace_str = APP_NAME;
            $file_content_string = file_get_contents(SRC_DIR_ROOT . "/Stubs/event_handle.stub.php");
            $count = 1;
            $file_content_string = str_replace($search_str, $replace_str, $file_content_string, $count);
            file_put_contents($eventServerFile, $file_content_string);
        }

        $autoloaderFile = $appPathDir . "/Autoloader.php";
        if (!file_exists($autoloaderFile)) {
            $replace_str = APP_NAME;
            $file_content_string = file_get_contents(dirname(SRC_DIR_ROOT) . "/Autoloader.php");
            $file_content_string = str_replace(
                ['__APP_NAMESPACE__', '<{APP_NAME}>'],
                [$replace_str, $replace_str],
                $file_content_string
            );
            file_put_contents($autoloaderFile, $file_content_string);
        }
    }

    /**
     * 获取 PID 文件路径（向后兼容的委托方法）。
     *
     * 委托给 PidFileManager::resolve()，自动创建目录。
     *
     * @param string $appName 应用名称
     * @return string PID 文件路径
     */
    protected function getPidFile(string $appName): string
    {
        return PidFileManager::resolve($appName);
    }

    /**
     * 创建日志目录、PID 目录，并清理已死亡进程的 PID 文件。
     *
     * @param array $config Protocol/conf.php 配置（引用传递，pid_file 可能被修正）
     * @throws CmdInitException 缺少 app_conf 配置时抛出
     */
    protected function makeDirLogAndPid(array &$config)
    {
        // 创建日志目录
        if (isset($config['setting']['log_file'])) {
            $path = pathinfo($config['setting']['log_file'], PATHINFO_DIRNAME);
            if (!is_dir($path)) {
                mkdir($path, 0777, true);
            }
        }

        // 创建 PID 目录
        if (isset($config['setting']['pid_file'])) {
            $path = pathinfo($config['setting']['pid_file'], PATHINFO_DIRNAME);
            if (!is_dir($path)) {
                mkdir($path, 0777, true);
            }
        }

        // WorkerService 的 Worker PID 目录
        if (SystemEnv::isWorkerService() && defined('WORKER_PID_FILE_ROOT')) {
            if (!is_dir(WORKER_PID_FILE_ROOT)) {
                mkdir(WORKER_PID_FILE_ROOT, 0777, true);
            }
        }

        // Script 模式下修正 PID 文件名（加端口后缀），并清理已死亡进程的 PID 文件
        if (isset($config['setting']['pid_file'])) {
            $path = pathinfo($config['setting']['pid_file'], PATHINFO_DIRNAME);
            $config['setting']['pid_file'] = parseScriptPidFile($config['setting']['pid_file']);

            // 仅清理当前 PID 文件（如果对应进程已死亡）
            PidFileManager::removeIfDead($config['setting']['pid_file']);
        }

        if (!isset($config['app_conf'])) {
            throw new CmdInitException(APP_NAME . '/Protocol/conf.php must include app_conf file and set app_conf');
        }
    }

    /**
     * 渲染服务进程状态表格（委托给 ServerStatusRenderer）。
     *
     * @param string $appName 应用名称
     * @param string $pidFile PID 文件路径
     */
    protected function serverStatus(string $appName, string $pidFile)
    {
        ServerStatusRenderer::render($appName, $pidFile);
    }

    /**
     * 判断当前是否为守护进程模式。
     */
    protected function isDaemon(): bool
    {
        return isDaemon();
    }

    /**
     * 加载全局配置（委托给 SystemEnv）。
     *
     * @return array Protocol/conf.php 配置
     */
    protected function loadGlobalConf(): array
    {
        return loadGlobalConf();
    }

    /**
     * 写入 CLI 控制日志（委托给 CmdLogWriter）。
     *
     * @param string $msg 日志消息
     */
    protected function writeLog(string $msg)
    {
        CmdLogWriter::write($msg);
    }
}
