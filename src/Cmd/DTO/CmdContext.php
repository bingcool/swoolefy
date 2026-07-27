<?php

declare(strict_types=1);

namespace Swoolefy\Cmd\DTO;

use Swoolefy\Core\SystemEnv;

/**
 * 命令上下文：封装单次命令执行所需的全部应用信息。
 *
 * 替代散落各处的全局常量（APP_PATH、WORKER_PORT、WORKER_PID_FILE 等），
 * 所有 Command 通过 $this->context() 统一获取。
 *
 * 设计原则：
 * - 不可变对象（readonly properties），构建后不允许修改
 * - 纯数据载体，无业务逻辑
 * - 通过 fromConfig() 静态工厂方法构建，集中配置读取逻辑
 */
final class CmdContext
{
    /**
     * @param string $appName          应用名称（如 'App'）
     * @param string $protocol         协议类型：http/websocket/rpc/udp/mqtt
     * @param string $appPath          应用根目录（如 /path/to/project/App）
     * @param array  $config           Protocol/conf.php 完整配置数组
     * @param string $pidFile          Swoole Server PID 文件路径
     * @param int    $pid              当前 PID 文件中的进程 ID（可能为 0，表示服务未运行）
     * @param int    $port             服务监听端口
     * @param string $serverClass      服务类全名（如 App\HttpServer）
     * @param bool   $isWorkerService  是否为 WorkerService（Cron/Daemon/Script）
     * @param string $workerPidFile    Worker PID 文件路径（仅 WorkerService 有效）
     * @param string $cliToWorkerPipe  CLI→Worker FIFO 管道路径
     * @param string $workerToCliPipe  Worker→CLI 响应管道路径
     * @param string $logFile          CLI 控制日志文件路径
     */
    public function __construct(
        public readonly string $appName,
        public readonly string $protocol,
        public readonly string $appPath,
        public readonly array $config,
        public readonly string $pidFile,
        public readonly int $pid,
        public readonly int $port,
        public readonly string $serverClass,
        public readonly bool $isWorkerService,
        public readonly string $workerPidFile = '',
        public readonly string $cliToWorkerPipe = '',
        public readonly string $workerToCliPipe = '',
        public readonly string $logFile = '',
    ) {}

    /**
     * 从 Protocol/conf.php 配置构建上下文。
     *
     * 集中所有配置读取逻辑于此，各 Command 不再直接读取全局常量。
     *
     * @param string $appName 应用名称
     * @param array  $appMeta APP_META_ARR 中该应用的元信息（含 protocol 等）
     */
    public static function fromConfig(string $appName, array $appMeta): self
    {
        $appPath = ROOT_PATH . '/' . $appName;
        $protocol = $appMeta['protocol'] ?? '';

        // 是否为 WorkerService（Cron/Daemon/Script 模式）
        // 必须通过 IS_DAEMON_SERVICE/IS_SCRIPT_SERVICE/IS_CRON_SERVICE 判断，
        // 不能用 defined('WORKER_SERVICE_NAME')，因为 cli.php 也定义了该常量
        $isWorkerService = SystemEnv::isWorkerService();

        // 读取 Protocol/conf.php 完整配置
        $configFile = $appPath . '/Protocol/conf.php';
        $config = is_file($configFile) ? (array) include $configFile : [];

        // 读取 PID 文件并解析当前 master PID
        $pidFile = (string) ($config['setting']['pid_file'] ?? '');
        $pid = 0;
        if ($pidFile && is_file($pidFile)) {
            $content = file_get_contents($pidFile);
            $pid = is_numeric($content) ? (int) $content : 0;
        }

        // 根据协议类型确定 Server 类名
        $protocolMap = [
            'http' => 'HttpServer',
            'rpc' => 'RpcServer',
            'udp' => 'UdpEventServer',
            'websocket' => 'WebsocketEventServer',
            'mqtt' => 'MqttServer',
        ];
        $serverClass = $appName . '\\' . ($protocolMap[$protocol] ?? '');

        return new self(
            appName: $appName,
            protocol: $protocol,
            appPath: $appPath,
            config: $config,
            pidFile: $pidFile,
            pid: $pid,
            port: (int) ($config['port'] ?? (defined('WORKER_PORT') ? WORKER_PORT : 0)),
            serverClass: $serverClass,
            isWorkerService: $isWorkerService,
            workerPidFile: defined('WORKER_PID_FILE') ? WORKER_PID_FILE : '',
            cliToWorkerPipe: defined('CLI_TO_WORKER_PIPE') ? CLI_TO_WORKER_PIPE : '',
            workerToCliPipe: defined('WORKER_TO_CLI_PIPE') ? WORKER_TO_CLI_PIPE : '',
            logFile: defined('WORKER_CTL_LOG_FILE') ? WORKER_CTL_LOG_FILE : '',
        );
    }

    /**
     * 获取进程名称配置（用于进程树清理和状态展示）。
     *
     * 从 Protocol/conf.php 读取 master_process_name 和 manager_process_name，
     * 若未配置则使用 HTTP 协议的默认值。
     *
     * @return array{master: string, manager: string}
     */
    public function processNames(): array
    {
        return [
            'master' => (string) ($this->config['master_process_name'] ?? 'php-swoolefy-http-master'),
            'manager' => (string) ($this->config['manager_process_name'] ?? 'php-swoolefy-http-manager'),
        ];
    }
}
