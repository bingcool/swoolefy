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

namespace Swoolefy\Worker\Traits;

use Swoolefy\Core\CommandRunner;
use Swoolefy\Core\SystemEnv;
use Swoolefy\Worker\AbstractBaseWorker;

/**
 * 父进程接收指令处理
 */
trait MainProcessCommandTrait {

    /**
     * @param string $processName
     * @return array
     */
    protected function parseLoadConf(string $processName): array
    {
        $conf = self::includeWorkerConf();
        // 新增-启动
        $confMap = array_column($conf, null, 'process_name');
        // 读取最新的配置
        $config = $confMap[$processName] ?? [];
        return $config;
    }

    /**
     * 向终端返回信息
     *
     * @param string $msg
     * @return mixed
     */
    protected function responseMsgByPipe(string $msg)
    {
        $workerToCliPipeFile = fopen(WORKER_TO_CLI_PIPE, 'w+');
        fwrite($workerToCliPipeFile, $msg);
        fclose($workerToCliPipeFile);
    }

    /**
     * @param string $processName
     * @return void
     */
    protected function restartWorkerProcessCommand(string $processName)
    {
        // 重启
        $key = md5($processName);
        if (isset($this->processWorkers[$key])) {
            $processList = $this->processWorkers[$key];
            foreach ($processList as $process) {
                $pid = $process->getPid();
                if (\Swoole\Process::kill($pid, 0)) {
                    $processName = $process->getProcessName();
                    $workerId = $process->getProcessWorkerId();
                    $this->writeByProcessName($processName, AbstractBaseWorker::WORKERFY_PROCESS_REBOOT_FLAG, $workerId);
                }
            }
        }
    }

    /**
     * @param array $config
     * @return void
     */
    protected function startWorkerProcessCommand(array $config)
    {
        if (empty($config)) {
            return;
        }

        $processName      = $config['process_name'];
        $processClass     = $config['handler'];
        if ($config['worker_num'] > $this->getMaxProcessNum()) {
            $config['worker_num'] = $this->getMaxProcessNum();
        }
        $processWorkerNum = $config['worker_num'] ?? 1;
        if (SystemEnv::isCronService()) {
            $processWorkerNum = 1;
        }
        $args             = $config['args'] ?? [];
        $extendData       = $config['extend_data'] ?? [];
        $this->parseArgs($args, $config);
        for ($workerId=0; $workerId < $processWorkerNum; $workerId++) {
            $this->forkNewProcess($processClass, $processName, $workerId, $args, $extendData);
        }
        $this->setProcessLists($processName, $processClass, $processWorkerNum, $args, $extendData);
    }

    /**
     * @param string $processName
     * @return void
     */
    protected function stopWorkerProcessCommand(string $processName)
    {
        $key = md5($processName);
        if (isset($this->processWorkers[$key])) {
            $processList = $this->processWorkers[$key];
            ksort($processList);
            /**
             * @var AbstractBaseWorker $process
             */
            foreach ($processList as $process) {
                $processName = $process->getProcessName();
                $workerId = $process->getProcessWorkerId();
                $this->writeByProcessName($processName, AbstractBaseWorker::WORKERFY_PROCESS_EXIT_FLAG, $workerId);
            }
        }

        if (isset($this->processLists[$key])) {
            unset($this->processLists[$key]);
        }
    }

    /**
     * @return void
     */
    protected function stopAllWorkerProcessCommand()
    {
        foreach ($this->processWorkers as $processes) {
            ksort($processes);
            /**
             * @var AbstractBaseWorker $process
             */
            foreach ($processes as $process) {
                $processName = $process->getProcessName();
                $workerId = $process->getProcessWorkerId();
                $this->writeByProcessName($processName, AbstractBaseWorker::WORKERFY_PROCESS_EXIT_FLAG, $workerId);
            }
        }
    }

    /**
     * 重启整个swoole server服务. 所有进程都将重启
     *
     * @return void
     */
    protected function reStartServerCommand()
    {
        $runner = CommandRunner::getInstance('restart-'.time());
        $runner->isNextHandle(false);
        $execBinFile = defined('PHP_BIN_FILE') ? PHP_BIN_FILE : '/usr/bin/php';
        $scriptFile  = WORKER_START_SCRIPT_FILE;
        $appName     = APP_NAME;
        list($command) = $runner->exec($execBinFile, "{$scriptFile} restart {$appName} --force=1", [],true, 'nobup_restart.log', false);
        exec($command, $output, $code);
    }
}