<?php
namespace Swoolefy\Cmd;

use Swoolefy\Core\Exec;
use Swoolefy\Core\SystemEnv;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Swoolefy\Worker\Dto\PipeMsgDtoWorker;

#[AsCommand(
    name: 'stop',
)]
class StopCmd extends BaseCmd
{
    protected static $defaultName = 'stop';
    
    // 定义睡眠时间
    private const SLEEP_INTERVAL_SECOND = 1;
    // 定义超时
    private const MAX_KILL_TIMEOUT = 10;
    private const MAX_STOP_TIMEOUT = 20;
    private const MAX_WAIT_INTERVAL_SECOND = 3;
    
    // 定义信号
    private const SIGNAL_TERMINATE = SIGTERM;
    private const SIGNAL_KILL = SIGKILL;
    
    // 日志前缀
    private const LOG_PREFIX = '[StopCmd]';

    protected function configure()
    {
        parent::configure();
        $this->setDescription('stop the application')
             ->setHelp('<info>use php cli.php stop XXXXX or php daemon.php stop XXXXX</info>');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $appName = $input->getArgument(self::APP_NAME);
            $force = $input->getOption(self::FORCE);
            
            if (!$this->confirmStop($appName, $force)) {
                $this->printCancelMessage($appName);
                return 0;
            }
            
            $this->performStop($appName);
            return 0;
            
        } catch (\Throwable $e) {
            fmtPrintError("Stop failed: " . $e->getMessage());
            $this->writeLog("停止服务失败: " . $e->getMessage());
            return 1;
        }
    }

    /**
     * 确认停止操作
     * 
     * @param string $appName
     * @param mixed $force
     * @return bool
     */
    private function confirmStop(string $appName, $force): bool
    {
        if (!empty($force)) {
            return true;
        }
        
        $prompt = SystemEnv::isWorkerService() 
            ? "你确定 [停止] workerService【" . WORKER_SERVICE_NAME . "】? (yes or no)"
            : "你确定 [停止] 应用【{$appName}】? (yes or no)";
            
        $lineValue = initConsoleStyleIo()->ask($prompt);
        return strtolower($lineValue) === 'yes';
    }
    
    /**
     * 打印取消消息
     * 
     * @param string $appName
     * @return void
     */
    private function printCancelMessage(string $appName): void
    {
        $message = SystemEnv::isWorkerService()
            ? "你已放弃停止workerService【" . WORKER_SERVICE_NAME . "】,应用继续running中"
            : "你已放弃停止应用{$appName},应用继续running中";
            
        fmtPrintInfo(PHP_EOL . $message);
    }
    
    /**
     * 执行停止操作
     * 
     * @param string $appName
     * @return void
     */
    private function performStop(string $appName): void
    {
        if (SystemEnv::isWorkerService()) {
            $this->workerStop($appName);
        } else {
            $this->serverStop($appName);
        }
        
        $this->writeLog("停止服务: " . (SystemEnv::isWorkerService() ? WORKER_SERVICE_NAME : $appName));
    }
    
    /**
     * 停止普通服务器
     * 
     * @param string $appName
     * @return void
     */
    protected function serverStop(string $appName): void
    {
        $pidFile = $this->getPidFile($appName);
        $this->validatePidFile($pidFile, $appName);
        
        $pid = $this->readMasterPid($pidFile);
        if (!$this->isProcessRunning($pid)) {
            fmtPrintInfo("Server Had Stopped!");
            return;
        }
        
        $this->terminateProcess($pid, $appName);
        $this->waitForProcessStop($pid, $pidFile, $appName);
        \Swoole\Process::wait();
    }

    /**
     * 停止Worker服务
     * 
     * @param string $appName
     * @return void
     */
    protected function workerStop(string $appName): void
    {
        $pidFile = $this->getPidFile($appName);
        $this->validatePidFile($pidFile, $appName);
        
        $masterPid = $this->readMasterPid($pidFile);
        if (!$this->isProcessRunning($masterPid)) {
            fmtPrintInfo("Worker Service Already Stopped!");
            return;
        }

        $workerPid = $this->readWorkerPid();
        $this->sendStopSignalToWorker($workerPid);
        sleep(self::MAX_WAIT_INTERVAL_SECOND);
        $this->serverStop($appName);
    }
    
    /**
     * 验证PID文件
     * 
     * @param string $pidFile
     * @return void
     * @throws \RuntimeException
     */
    private function validatePidFile(string $pidFile): void
    {
        if (!is_file($pidFile)) {
            $errorMessage = "Pid file={$pidFile} does not exist, please check if the server is running";
            fmtPrintError($errorMessage);
            throw new \RuntimeException($errorMessage);
        }
    }
    
    /**
     * 读取主进程PID
     * 
     * @param string $pidFile
     * @return int
     * @throws \RuntimeException
     */
    private function readMasterPid(string $pidFile): int
    {
        $pidContent = file_get_contents($pidFile);
        $masterPid = is_numeric($pidContent) ? (int)$pidContent : 0;
        
        if ($masterPid <= 0) {
            $errorMessage = "Master PID is invalid: {$pidContent}";
            fmtPrintError($errorMessage);
            throw new \RuntimeException($errorMessage);
        }
        
        return $masterPid;
    }
    
    /**
     * 检查进程是否正在运行
     * 
     * @param int $pid
     * @return bool
     */
    private function isProcessRunning(int $pid): bool
    {
        return $pid > 0 && \Swoole\Process::kill($pid, 0);
    }
    
    /**
     * 终止进程
     * 
     * @param int $pid
     * @param string $appName
     * @return void
     */
    private function terminateProcess(int $pid, string $appName): void
    {
        fmtPrintInfo(sprintf(
            "[%s]Server begin to stopping at %s, pid=%d. Please wait a moment...",
            $appName,
            date("Y-m-d H:i:s"),
            $pid
        ));
        
        \Swoole\Process::kill($pid, self::SIGNAL_TERMINATE);
    }
    
    /**
     * 等待进程停止
     * 
     * @param int $pid
     * @param string $pidFile
     * @param string $appName
     * @return void
     */
    private function waitForProcessStop(int $pid, string $pidFile, string $appName): void
    {
        $startTime = time();
        
        while (true) {
            sleep(self::SLEEP_INTERVAL_SECOND);
            
            if (!$this->isProcessRunning($pid)) {
                $this->handleSuccessfulStop($pidFile, $appName);
                break;
            }
            
            if ($this->shouldForceKill($startTime)) {
                $this->forceKillProcesses($pid);
                sleep(self::SLEEP_INTERVAL_SECOND);
            }
            
            if ($this->isStopTimeout($startTime)) {
                $this->handleStopTimeout($pid);
                break;
            }
        }
    }
    
    /**
     * 处理成功停止
     * 
     * @param string $pidFile
     * @param string $appName
     * @return void
     */
    private function handleSuccessfulStop(string $pidFile, string $appName): void
    {
        fmtPrintNote("---------------------stop info-------------------");
        fmtPrintNote(sprintf(
            "【%s】 Server Stopped Successfully at %s",
            $appName,
            date("Y-m-d H:i:s")
        ));
        @unlink($pidFile);
    }
    
    /**
     * 判断是否应该强制杀死进程
     * 
     * @param int $startTime
     * @return bool
     */
    private function shouldForceKill(int $startTime): bool
    {
        return (time() - $startTime) > self::MAX_KILL_TIMEOUT;
    }
    
    /**
     * 判断停止是否超时
     * 
     * @param int $startTime
     * @return bool
     */
    private function isStopTimeout(int $startTime): bool
    {
        return (time() - $startTime) > self::MAX_STOP_TIMEOUT;
    }
    
    /**
     * 强制杀死进程树
     * 
     * @param int $masterPid
     * @return void
     */
    private function forceKillProcesses(int $masterPid): void
    {
        $processIds = $this->getAllRelatedProcessIds($masterPid);
        foreach ($processIds as $processId) {
            if ($processId > 0 && $this->isProcessRunning($processId)) {
                \Swoole\Process::kill($processId, self::SIGNAL_KILL);
            }
        }
    }
    
    /**
     * 获取所有相关的进程ID
     * 
     * @param int $masterPid
     * @return array
     */
    private function getAllRelatedProcessIds(int $masterPid): array
    {
        $managerPid = $this->getChildProcessId($masterPid);
        $workerPids = $managerPid > 0 ? $this->getChildProcessIds($managerPid) : [];
        
        return [$masterPid, $managerPid, ...$workerPids];
    }
    
    /**
     * 获取子进程ID
     * 
     * @param int $parentPid
     * @return int
     */
    private function getChildProcessId(int $parentPid): int
    {
        $exec = (new Exec())->run('pgrep -P ' . $parentPid);
        $output = $exec->getOutput();
        return isset($output[0]) ? (int)current($output) : -1;
    }
    
    /**
     * 获取所有子进程IDs
     * 
     * @param int $parentPid
     * @return array
     */
    private function getChildProcessIds(int $parentPid): array
    {
        $exec = (new Exec())->run('pgrep -P ' . $parentPid);
        $output = $exec->getOutput();
        return array_map('intval', $output);
    }
    
    /**
     * 处理停止超时
     * 
     * @param int $masterPid
     * @return void
     */
    private function handleStopTimeout(int $masterPid): void
    {
        fmtPrintNote("---------------------------stop info-----------------------");
        fmtPrintNote("Stop timeout reached. Force killing remaining processes.");
        $this->forceKillProcesses($masterPid);
        fmtPrintNote("Please use 'ps -ef | grep php-swoolefy' to check if processes are stopped");
    }
    
    /**
     * 向Worker发送停止信号
     * @param int $workerPid
     * @return void
     */
    private function sendStopSignalToWorker($workerPid): void
    {
        try {
            if (!defined('CLI_TO_WORKER_PIPE')) {
                return;
            }
            if ($workerPid > 0 && $this->isProcessRunning($workerPid)) {
                $this->sendPipeMessage(CLI_TO_WORKER_PIPE, WORKER_CLI_STOP);
            }
        } catch (\Throwable $e) {
            fmtPrintError("Failed to send stop signal to worker: " . $e->getMessage());
        }
    }
    
    /**
     * 读取Worker PID
     * 
     * @return int
     */
    private function readWorkerPid(): int
    {
        if (!defined('WORKER_PID_FILE') || !is_file(WORKER_PID_FILE)) {
            return 0;
        }
        
        $workerPid = file_get_contents(WORKER_PID_FILE);
        return is_numeric($workerPid) ? (int)$workerPid : 0;
    }
    
    /**
     * 发送管道消息
     * 
     * @param string $cliToWorkerPipe
     * @param string $action
     * @return void
     */
    private function sendPipeMessage(string $cliToWorkerPipe, string $action): void
    {
        $pipeMsgDto = new PipeMsgDtoWorker();
        $pipeMsgDto->action = $action;
        $pipeMsg = serialize($pipeMsgDto);
        
        $pipe = @fopen($cliToWorkerPipe, 'w+');
        if ($pipe === false) {
            return;
        }
        
        try {
            if (flock($pipe, LOCK_EX)) {
                fwrite($pipe, $pipeMsg);
                flock($pipe, LOCK_UN);
            }
        } finally {
            fclose($pipe);
        }
    }
}