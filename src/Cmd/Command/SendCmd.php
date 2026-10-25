<?php

declare(strict_types=1);

namespace Swoolefy\Cmd\Command;

use Swoolefy\Cmd\Application\ServerLifecycleManager;
use Swoolefy\Cmd\BaseCmd;
use Swoolefy\Cmd\Infrastructure\PidFileManager;
use Swoolefy\Cmd\Support\ProcessTreeTerminator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 发送消息命令。
 *
 * 经 UDS 控制面向 WorkerService 主进程发一帧请求并同步读取响应，
 * 响应文本直接打印；传输失败与业务拒绝都返回 FAILURE，不再静默等超时。
 *
 * 仅支持 WorkerService（Cron/Daemon/Script），普通 Swoole Server 不支持。
 *
 * 使用方式：
 *   php daemon.php send App --name=ProcessName --action=start --msg=xxx
 */
#[AsCommand(name: 'send')]
class SendCmd extends BaseCmd
{
    protected function configure()
    {
        parent::configure();
        $this->setDescription('send message to the worker service')
             ->setHelp('<info>use php daemon.php send XXXXX --name=xxxxx --action=start|restart|stop|自定义指令 --msg=xxx</info>');
    }

    /**
     * 执行发送消息命令。
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->hasInitError()) {
            return Command::FAILURE;
        }

        // send 命令仅支持 WorkerService
        if (!isWorkerService()) {
            fmtPrintError("send command only for Worker,Cron Service, do not support cli application");
            return Command::SUCCESS;
        }

        $ctx = $this->context();
        $processName = getenv('name');
        $action = getenv('action');
        $msg = getenv('msg') ?: '';

        // 校验 PID 文件
        $pidFile = $ctx->pidFile;
        if (!is_file($pidFile)) {
            fmtPrintError("Pid file={$pidFile} is not exist, please check the server whether running");
            return Command::FAILURE;
        }

        $masterPid = PidFileManager::read($pidFile);
        if ($masterPid <= 0) {
            fmtPrintError("Master Pid is invalid");
            return Command::FAILURE;
        }

        // start/restart 动作时执行全局前置回调
        global $beforeFunc;
        if (in_array($action, ['start', 'restart']) && isset($beforeFunc) && is_callable($beforeFunc)) {
            call_user_func($beforeFunc);
        }

        if (!ProcessTreeTerminator::isAlive($masterPid)) {
            return Command::SUCCESS;
        }

        $manager = new ServerLifecycleManager();
        $response = $manager->sendWorkerControlRequest(
            $ctx,
            WORKER_CLI_SEND_MSG,
            is_string($processName) ? $processName : '',
            is_string($action) ? $action : '',
            is_string($msg) ? $msg : ''
        );

        $text = $response->message;
        if ($response->data !== '') {
            $text .= ($text !== '' ? PHP_EOL : '') . $response->data;
        }

        if (!$response->ok) {
            fmtPrintError($text !== '' ? $text : 'Worker 控制面未返回原因');
            return Command::FAILURE;
        }

        fmtPrintInfo($text !== '' ? $text : '已向master进程发起跑脚本指令');

        return Command::SUCCESS;
    }
}
