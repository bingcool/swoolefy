<?php

declare(strict_types=1);

namespace Swoolefy\Cmd\Command;

use Swoolefy\Cmd\BaseCmd;
use Swoolefy\Cmd\Infrastructure\CmdLogWriter;
use Swoolefy\Core\SystemEnv;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 启动服务命令。
 *
 * 根据 APP_META_ARR 中配置的协议类型（http/websocket/rpc/udp/mqtt），
 * 实例化对应的 Server 类并启动。
 *
 * 使用方式：
 *   php cli.php start App
 *   php daemon.php start App --daemon=1
 *
 * 改造说明：
 * - 移除了原先 5 个完全相同的 startHttpServer/startWebsocket 等方法
 * - 直接通过 protocolMap 查表调用 startServer()
 * - exit(0) 改为 return Command::FAILURE/SUCCESS
 * - 使用 CmdContext 替代全局常量
 */
#[AsCommand(name: 'start')]
class StartCmd extends BaseCmd
{
    /**
     * 注册 start 命令独有的选项。
     *
     * --start_model：标记启动模式（restart 表示由 restart 命令触发）
     */
    protected function configure()
    {
        $this->addOption(self::START_MODEL, null, InputOption::VALUE_OPTIONAL, 'start model', '');
        parent::configure();

        // 清理 restart 模式的 PID 文件（避免误判为新启动）
        $restartPidFile = SystemEnv::getRestartModelPidFile();
        if (file_exists($restartPidFile)) {
            unlink($restartPidFile);
        }

        $this->setDescription('start the application')
             ->setHelp('<info>use php cli.php start XXXXX or php daemon.php start XXXXX</info>');
    }

    /**
     * 执行启动命令。
     *
     * 流程：
     * 1. 执行全局前置回调 $beforeFunc（如有）
     * 2. 从 APP_META_ARR 中查找目标应用的协议类型
     * 3. 通过 protocolMap 获取 Server 类名，实例化并启动
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->hasInitError()) {
            return Command::FAILURE;
        }

        // 执行全局前置回调（框架扩展点，入口脚本中定义）
        global $beforeFunc;
        if (isset($beforeFunc) && is_callable($beforeFunc)) {
            call_user_func($beforeFunc);
        }

        $serverName = $input->getArgument(self::APP_NAME);

        foreach (APP_META_ARR as $appName => $appItem) {
            if ($appName !== $serverName) {
                continue;
            }

            $protocol = $appItem['protocol'];
            if (!isset($this->protocolMap[$protocol])) {
                fmtPrintError("Unsupported protocol: {$protocol}");
                return Command::FAILURE;
            }

            try {
                $this->startServer($appName, $this->protocolMap[$protocol]['server_name']);
            } catch (\Throwable $e) {
                fmtPrintError($e->getMessage() . ', trace=' . $e->getTraceAsString());
                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
    }

    /**
     * 实例化 Server 类并启动。
     *
     * 启动流程：
     * 1. 写入启动日志
     * 2. 加载 Protocol/conf.php 全局配置
     * 3. 检查服务是否已运行（checkRunning）
     * 4. 实例化 Server 类并调用 start()
     *
     * @param string $appName    应用名称
     * @param string $serverName Server 类短名（如 HttpServer）
     */
    protected function startServer(string $appName, string $serverName): void
    {
        CmdLogWriter::write("启动服务：" . (defined('WORKER_SERVICE_NAME') ? WORKER_SERVICE_NAME : $appName));
        $config = $this->loadGlobalConf();
        $this->checkRunning($config);

        // 实例化并启动 Swoole Server
        $class = "{$appName}\\{$serverName}";
        $server = new $class($config);
        $server->start();
    }
}
