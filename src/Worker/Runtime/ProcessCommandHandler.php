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

declare(strict_types=1);

namespace Swoolefy\Worker\Runtime;

use Swoolefy\Core\CommandRunner;
use Swoolefy\Core\SystemEnv;
use Swoolefy\Worker\AbstractBaseWorker;
use Swoolefy\Worker\Config\WorkerConfLoader;
use Swoolefy\Worker\Helper;
use Swoolefy\Worker\MainManager;
use Swoolefy\Worker\Process\ProcessRegistry;

/**
 * CLI FIFO 与 CtlApi 共用的进程控制命令。
 *
 * START 指定进程必须经 parseLoadConf（已按当前实例 --group 过滤），跨组返回空配置，禁止拉起。
 * 整机 RESTART 通过 Helper::workerRestartCliSuffix() 回传 --group/--only，新实例身份与当前一致。
 */
final class ProcessCommandHandler
{
    public function __construct(private MainManager $manager)
    {
    }

    /**
     * 从当前实例可见的 worker conf 取指定进程。
     * 找不到（含其它分组的进程）返回 []，调用方据此拒绝 START。
     *
     * @return array<string, mixed>
     */
    public function parseLoadConf(string $processName): array
    {
        $conf = WorkerConfLoader::includeWorkerConf();
        $confMap = array_column($conf, null, 'process_name');

        return $confMap[$processName] ?? [];
    }

    /**
     * 经 WORKER_TO_CLI_PIPE 回写终端，供 php daemon.php 同步打印结果。
     */
    public function responseMsgByPipe(string $msg): void
    {
        $workerToCliPipeFile = fopen(WORKER_TO_CLI_PIPE, 'w+');
        fwrite($workerToCliPipeFile, $msg);
        fclose($workerToCliPipeFile);
    }

    /**
     * 向该进程名下仍存活的 worker 发 REBOOT_FLAG，由子进程自行排空后 SIGUSR1 退出再被 Master 拉起。
     */
    public function restartWorkerProcessCommand(string $processName): void
    {
        $workers = $this->registry()->getWorkersByName($processName);
        if ($workers === null) {
            return;
        }
        foreach ($workers as $process) {
            $pid = $process->getPid();
            if (\Swoole\Process::kill($pid, 0)) {
                $name = $process->getProcessName();
                $workerId = $process->getProcessWorkerId();
                $this->manager->writeByProcessName($name, AbstractBaseWorker::WORKERFY_PROCESS_REBOOT_FLAG, $workerId);
            }
        }
    }

    /**
     * 热启动一个尚未在 lists 中的静态进程（CLI start 指定进程）。
     * Cron 仍限制 worker_num=1；fork 类型为 PROCESS_STATIC_TYPE。
     *
     * @param array<string, mixed> $config
     */
    public function startWorkerProcessCommand(array $config): void
    {
        if (empty($config)) {
            return;
        }

        $processName = $config['process_name'];
        $processClass = $config['handler'];
        if (($config['worker_num'] ?? 1) > $this->manager->getSupervisor()->maxProcessNum()) {
            $config['worker_num'] = $this->manager->getSupervisor()->maxProcessNum();
        }
        $processWorkerNum = $config['worker_num'] ?? 1;
        if (SystemEnv::isCronService()) {
            $processWorkerNum = 1;
        }
        $args = $config['args'] ?? [];
        $extendData = $config['extend_data'] ?? [];
        $this->manager->getSupervisor()->parseArgs($args, $config);
        for ($workerId = 0; $workerId < $processWorkerNum; $workerId++) {
            $this->manager->invokeForkNewProcess(
                $processClass,
                $processName,
                $workerId,
                $args,
                $extendData,
                AbstractBaseWorker::PROCESS_STATIC_TYPE
            );
        }
        $this->manager->getSupervisor()->setProcessLists($processName, $processClass, $processWorkerNum, $args, $extendData);
    }

    /**
     * 停止指定进程：发 EXIT 并从 lists 删除，避免 status 仍显示待启动。
     */
    public function stopWorkerProcessCommand(string $processName): void
    {
        $workers = $this->registry()->getWorkersByName($processName);
        if ($workers !== null) {
            ksort($workers);
            /** @var AbstractBaseWorker $process */
            foreach ($workers as $process) {
                $name = $process->getProcessName();
                $workerId = $process->getProcessWorkerId();
                $this->manager->writeByProcessName($name, AbstractBaseWorker::WORKERFY_PROCESS_EXIT_FLAG, $workerId);
            }
        }

        if ($this->registry()->hasList($processName)) {
            $this->registry()->unsetList($processName);
        }
    }

    /**
     * 关机路径：通知全部业务子进程退出（不含 MainManager 自己）。
     */
    public function stopAllWorkerProcessCommand(): void
    {
        foreach ($this->registry()->allWorkers() as $processes) {
            ksort($processes);
            /** @var AbstractBaseWorker $process */
            foreach ($processes as $process) {
                $processName = $process->getProcessName();
                $workerId = $process->getProcessWorkerId();
                $this->manager->writeByProcessName($processName, AbstractBaseWorker::WORKERFY_PROCESS_EXIT_FLAG, $workerId);
            }
        }
    }

    /**
     * 重启整个 Swoole Server（所有 Worker + Master）。
     *
     * 必须带上 `--group` / `--only`：WORKER_SERVICE_NAME 在 argv 解析阶段已定，
     * 不回传则新实例会落到默认 PID 目录，和旧实例抢文件或启动「全组」。
     */
    public function restartServerCommand(): void
    {
        $runner = CommandRunner::getInstance('restart-' . time());
        $runner->isNextHandle(false);
        $execBinFile = SystemEnv::PhpBinFile();
        $scriptFile = WORKER_START_SCRIPT_FILE;
        $appName = APP_NAME;
        $extra = Helper::workerRestartCliSuffix();
        $execParts = [$scriptFile, 'restart', $appName, '--force=1'];
        if ($extra !== '') {
            $execParts[] = $extra;
        }
        $execScript = implode(' ', $execParts);
        list($command) = $runner->exec($execBinFile, $execScript, [], true, 'nobup_restart.log', false);
        exec($command, $output, $code);
    }

    /**
     * CLI 动态加进程（历史接口）：lists 中已有该名才允许 createDynamicProcess。
     */
    public function addProcessByCli(string $processName, int $num = 1): void
    {
        if ($this->registry()->hasList($processName)) {
            $this->manager->createDynamicProcess($processName, $num);
        } else {
            $this->manager->logInfo("Not exist children_process_name = {$processName}, so add failed");
        }
    }

    /**
     * CLI 动态减进程：lists 中无该名则拒绝，避免误销毁。
     */
    public function removeProcessByCli(string $processName, int $num = 1): void
    {
        if ($this->registry()->hasList($processName)) {
            $this->manager->destroyDynamicProcess($processName, $num);
        } else {
            $this->manager->logError("Not exist children_process_name = {$processName}, remove failed");
        }
    }

    /**
     * Registry 只读入口。
     */
    private function registry(): ProcessRegistry
    {
        return $this->manager->getRegistry();
    }
}
