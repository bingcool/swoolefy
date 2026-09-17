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

use Swoolefy\Core\Memory\SysvmsgManager;
use Swoolefy\Core\Swfy;
use Swoolefy\Core\SystemEnv;
use Swoolefy\Core\Table\TableManager;
use Swoolefy\Worker\AbstractBaseWorker;
use Swoolefy\Worker\Helper;
use Swoolefy\Worker\MainManager;
use Swoolefy\Worker\Process\ProcessRegistry;

/**
 * 周期把 Master/Worker runtime 写入 WORKER_STATUS_FILE，并响应 CLI status。
 *
 * 只读 Registry。Timer 回调禁止开协程：Master 若存在异步 IO，后续 fork 会
 * `unable to create Swoole\Process with async-io threads`。
 */
final class StatusReporter
{
    /**
     * tick timer id。Swoole Timer::tick 失败可能返回 false，必须用 ?int 且校验 is_int。
     * 关机时必须 clear，否则 EventLoop 仍有 watcher，Master 退不出去。
     */
    private ?int $reportStatusTimerId = null;

    public function __construct(private MainManager $manager)
    {
    }

    /**
     * 安装状态上报定时器。tick 间隔下限 REPORT_STATUS_TICK_TIME(5s)。
     */
    public function install(): void
    {
        $defaultTickTime = MainManager::REPORT_STATUS_TICK_TIME;
        $config = $this->manager->getWorkerConfig();
        if (isset($config['report_status_tick_time'])) {
            $tickTime = $config['report_status_tick_time'];
        } else {
            $tickTime = $defaultTickTime;
        }

        if ($tickTime < $defaultTickTime) {
            $tickTime = $defaultTickTime;
        }

        $timerId = \Swoole\Timer::tick($tickTime * 1000, function () {
            try {
                $status = $this->getProcessStatus();
                // Script 服务不落 status 文件
                if (!SystemEnv::isScriptService()) {
                    file_put_contents(WORKER_STATUS_FILE, json_encode($status, JSON_UNESCAPED_UNICODE));
                }
                if (is_callable($this->manager->onReportStatus)) {
                    $this->manager->onReportStatus->call($this->manager, $status);
                }
            } catch (\Throwable $throwable) {
                $this->manager->handleWorkerException($throwable);
            }
        });

        $this->reportStatusTimerId = is_int($timerId) ? $timerId : null;

        if ($this->reportStatusTimerId !== null) {
            register_shutdown_function(function () {
                $this->clearTimer();
            });
        }
    }

    /**
     * 清 timer，避免子进程全退后 EventLoop 仍存活。
     */
    public function clearTimer(): void
    {
        if ($this->reportStatusTimerId !== null) {
            \Swoole\Timer::clear($this->reportStatusTimerId);
            $this->reportStatusTimerId = null;
        }
    }

    /**
     * 采集 Master + 存活子进程状态。会向各 worker 发 STATUS_FLAG 索取 runtime。
     * 顺带 rebootOrExitHandle，把已死 pid 从 Registry 清掉（loop report 兼回收）。
     *
     * @return array<string, mixed>
     */
    public function getProcessStatus(int $runningStatus = 1): array
    {
        $status = [];
        $childrenNum = 0;
        foreach ($this->registry()->allWorkers() as $processes) {
            $childrenNum += count($processes);
            ksort($processes);
            /** @var AbstractBaseWorker $process */
            foreach ($processes as $process) {
                $processName = $process->getProcessName();
                $workerId = $process->getProcessWorkerId();
                $this->manager->writeByProcessName($processName, AbstractBaseWorker::WORKERFY_PROCESS_STATUS_FLAG, $workerId);
            }
        }

        $cpuNum = swoole_cpu_num();
        $phpVersion = PHP_VERSION;
        $swooleVersion = swoole_version();
        $enableCliPipe = $this->manager->getCliPipeServer()->isPipeOpen() ? 1 : 0;
        $swooleTableInfo = $this->getSwooleTableInfo(false);
        $cliParams = $this->getOptionParams(true);
        $hostName = gethostname();
        list($msgSysvmsgInfo, $sysKernel) = $this->getSysvmsgInfo();

        $status['master'] = [
            'start_script_file' => WORKER_START_SCRIPT_FILE,
            'pid_file' => WORKER_PID_FILE,
            'running_status' => $runningStatus,
            'cli_params' => $cliParams,
            'worker_master_pid' => $this->manager->getMasterPid(),
            'cpu_num' => $cpuNum,
            'memory' => Helper::getMemoryUsage(),
            'php_version' => $phpVersion,
            'swoole_version' => $swooleVersion,
            'enable_cli_pipe' => $enableCliPipe,
            'hostname' => $hostName,
            'msg_sysvmsg_kernel' => $sysKernel,
            'msg_sysvmsg_info' => $msgSysvmsgInfo,
            'swoole_table_info' => $swooleTableInfo,
            'children_num' => $childrenNum,
            'children_process' => [],
            'stop_time' => !$runningStatus ? date("Y-m-d H:i:s") : '',
            'report_time' => date("Y-m-d H:i:s"),
        ];

        $runningChildrenNum = 0;
        $childrenStatus = [];
        foreach ($this->registry()->allWorkers() as $processes) {
            ksort($processes);
            foreach ($processes as $process) {
                /** @var AbstractBaseWorker $process */
                $processName = $process->getProcessName();
                $workerId = $process->getProcessWorkerId();
                $pid = $process->getPid();
                $startTime = $process->getStartTime();
                if (is_numeric($startTime)) {
                    $startTime = date('Y-m-d H:i:s', $startTime);
                }
                $rebootCount = $process->getRebootCount();
                $processType = $process->getProcessType();
                if ($processType == AbstractBaseWorker::PROCESS_STATIC_TYPE) {
                    $processType = AbstractBaseWorker::PROCESS_STATIC_TYPE_NAME;
                } else {
                    $processType = AbstractBaseWorker::PROCESS_DYNAMIC_TYPE_NAME;
                }
                if (\Swoole\Process::kill($pid, 0)) {
                    $this->manager->getSupervisor()->rebootOrExitHandle();
                    $processStatus = 'running';
                    $childrenStatus[$processName][$workerId] = [
                        'process_name' => $processName,
                        'worker_id' => $workerId,
                        'pid' => $pid,
                        'process_type' => $processType,
                        'start_time' => $startTime,
                        'reboot_count' => $rebootCount,
                        'status' => $processStatus,
                        'runtime' => $this->registry()->getRuntimeStatus($processName, $workerId),
                        'description' => $this->registry()->getList($processName)['args']['description'] ?? '',
                    ];
                    $runningChildrenNum++;
                }
            }
            $status['master']['children_process'] = $childrenStatus;
            unset($processes);
        }

        if (empty($status['master']['children_process'])) {
            foreach ($this->registry()->allRuntimeStatus() as $processName => $item) {
                foreach ($item as $workerId => $runtime) {
                    $status['master']['children_process'][$processName][$workerId]['runtime'] = $runtime;
                }
            }
        }
        $status['master']['children_num'] = $runningChildrenNum;

        return $status;
    }

    /**
     * 立刻写 WORKER_STATUS_FILE。start() 末尾与 timer 共用。
     */
    public function saveStatusToFile(array $status = []): void
    {
        if (empty($status)) {
            $status = $this->getProcessStatus();
        }

        if (!SystemEnv::isScriptService()) {
            @file_put_contents(WORKER_STATUS_FILE, json_encode($status, JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * CLI status：把 Master/Children runtime 文本写入回传 FIFO（targetHandler）。
     */
    public function masterStatusToCliFifoPipe(string $ctlPipeFile): void
    {
        $ctlPipe = fopen($ctlPipeFile, 'w+');
        $masterInfo = $this->statusInfoFormat(
            $this->manager->getMasterWorkerName(),
            $this->manager->getMasterWorkerId(),
            $this->manager->getMasterPid(),
            'running',
            (string) $this->manager->getStartTime()
        );
        $separator = PHP_EOL;
        fwrite($ctlPipe, 'Master Process Runtime:' . $separator);
        fwrite($ctlPipe, str_repeat('-', 50) . $separator);
        fwrite($ctlPipe, $masterInfo, null);
        fwrite($ctlPipe, str_repeat('-', 50) . $separator . $separator);
        fwrite($ctlPipe, 'Children Process Runtime:' . $separator);
        foreach ($this->registry()->allWorkers() as $processes) {
            ksort($processes);
            /** @var AbstractBaseWorker $process */
            foreach ($processes as $process) {
                $processName = $process->getProcessName();
                $workerId = $process->getProcessWorkerId();
                $pid = $process->getPid();
                $startTime = $process->getStartTime();
                if (is_numeric($startTime)) {
                    $startTime = date('Y-m-d H:i:s', $startTime);
                }
                $rebootCount = $process->getRebootCount();
                $processType = $process->getProcessType();
                if ($processType == AbstractBaseWorker::PROCESS_STATIC_TYPE) {
                    $processType = AbstractBaseWorker::PROCESS_STATIC_TYPE_NAME;
                } else {
                    $processType = AbstractBaseWorker::PROCESS_DYNAMIC_TYPE_NAME;
                }

                if (\Swoole\Process::kill($pid, 0)) {
                    $this->manager->getSupervisor()->rebootOrExitHandle();
                    $status = 'running';
                } else {
                    $status = 'stop';
                }
                $info = $this->statusInfoFormat(
                    $processName,
                    $workerId,
                    $pid,
                    $status,
                    (string) $startTime,
                    $rebootCount,
                    $processType
                );
                @fwrite($ctlPipe, $info, null);
                if ($status == 'stop') {
                    $this->manager->logInfo($info);
                }
            }
            unset($processes);
        }
        @fclose($ctlPipe);
    }

    /**
     * 终端可读的一行/一块状态文本。Master 与 Worker 格式不同。
     */
    private function statusInfoFormat(
        string $processName,
        int $workerId,
        int $pid,
        string $status,
        string $startTime = '',
        int $rebootCount = 0,
        string $processType = ''
    ): string {
        if ($processName == $this->manager->getMasterWorkerName()) {
            $childrenNum = $this->registry()->countWorkers();
            $pid = Swfy::getMasterPid();
            $startScriptFile = WORKER_START_SCRIPT_FILE;
            $pidFile = WORKER_PID_FILE;
            $cpuNum = swoole_cpu_num();
            $memory = Helper::getMemoryUsage();
            $phpVersion = PHP_VERSION;
            $swooleVersion = swoole_version();
            $enableCliPipe = $this->manager->getCliPipeServer()->isPipeOpen() ? 1 : 0;
            list($msgSysvmsgInfo, $sysKernel) = $this->getSysvmsgInfo();
            $swooleTableInfo = $this->getSwooleTableInfo();
            $cliParams = $this->getOptionParams(false);
            $maxNum = $this->manager->getSupervisor()->maxProcessNum();
            $hostname = gethostname();
            $infoItem = [
                'master_name' => $processName,
                'master_worker_id(default 0)' => $workerId,
                'swoole_master_pid' => $pid,
                'master_status' => $status,
                'start_time' => $startTime,
                'cli_option_params' => $cliParams,
                'start_script_file' => $startScriptFile,
                'pid_file' => $pidFile,
                'children_num' => $childrenNum,
                'cpu_num' => $cpuNum,
                'max_process_num(cpu_num * 8)' => $maxNum,
                'memory' => $memory,
                'php_version' => $phpVersion,
                'swoole_version' => $swooleVersion,
                'enable_cli_pipe' => $enableCliPipe,
                'sysvmsg_kernel' => $sysKernel,
                'sysvmsg_status' => $msgSysvmsgInfo,
                'swoole_table_name' => $swooleTableInfo,
                'hostname' => $hostname,
            ];
            $formattedData = [];
            foreach ($infoItem as $name => $value) {
                $formattedData[] = $name . ': ' . $value;
            }
            $info = implode(PHP_EOL, $formattedData) . PHP_EOL;
        } else {
            $memory = $this->registry()->getRuntimeStatus($processName, $workerId)['memory'] ?? '--';
            $info = "【{$processName}@{$workerId}】【{$processType}】: 进程名称name: $processName, 进程编号worker_id: $workerId, 进程Pid: $pid, 进程状态status：$status, 启动(重启)时间：$startTime, 内存占用：$memory, reboot次数：$rebootCount\n-----------\n";
        }

        return $info;
    }

    /**
     * Swoole Table 摘要。simple=true 只列 table 名（CLI 短输出）；false 给 JSON status。
     *
     * @return string|array
     */
    private function getSwooleTableInfo(bool $simple = true)
    {
        $swooleTableInfo = "Disable swoole table (unenabled)";
        $tableManager = TableManager::getInstance();
        if ($simple) {
            $allTableName = $tableManager->getAllTableName();
            if (!empty($allTableName) && is_array($allTableName)) {
                $allTableNameStr = implode(',', $allTableName);
                $swooleTableInfo = "[{$allTableNameStr}]";
            }
        } else {
            $allTableInfo = $tableManager->getAllTableKeyMapRowValue();
            if (!empty($allTableInfo)) {
                $swooleTableInfo = $allTableInfo;
            } else {
                $swooleTableInfo = "swoole table (enabled), but missing table_name";
            }
        }

        return $swooleTableInfo;
    }

    /**
     * SysV 消息队列积压与内核参数（msgmax/msgmnb/msgmni），便于排查 queue 打满。
     *
     * @return array{0:string,1:string}
     */
    private function getSysvmsgInfo(): array
    {
        $msgSysvmsgInfo = 'Disable sysvmsg (unenable)';
        $sysvmsgManager = SysvmsgManager::getInstance();
        if (defined('ENABLE_WORKERFY_SYSVMSG_MSG') && ENABLE_WORKERFY_SYSVMSG_MSG == 1) {
            $msgQueueInfo = $sysvmsgManager->getAllMsgQueueWaitToPopNum();
            if (!empty($msgQueueInfo)) {
                $msgSysvmsgInfo = '';
                foreach ($msgQueueInfo as $info) {
                    list($msgQueueName, $waitToReadNum) = $info;
                    $msgSysvmsgInfo .= "[queue_name:$msgQueueName,queue_number:$waitToReadNum]" . ',';
                }
                $msgSysvmsgInfo = trim($msgSysvmsgInfo, ',');
            }
        }
        $sysKernelInfo = array_values($sysvmsgManager->getSysKernelInfo(true));
        list($msgmax, $msgmnb, $msgmni) = $sysKernelInfo;
        $sysKernel = "[单个消息体最大字节msgmax:{$msgmax},队列的最大容量msgmnb:{$msgmnb},队列最大个数:{$msgmni}]";

        return [$msgSysvmsgInfo, $sysKernel];
    }

    /**
     * 从 ENV_CLI_PARAMS 拼 `--k=v`。CLI 短输出截断 1000 字符；JSON status 用 $showAll=true。
     */
    private function getOptionParams(bool $showAll = false): string
    {
        $cliParams = '';
        $envCliParams = getenv('ENV_CLI_PARAMS') ? json_decode(getenv('ENV_CLI_PARAMS'), true) : [];

        foreach ($envCliParams as $env => $value) {
            if (in_array($env, ['help', 'quiet', 'verbose', 'version', 'ansi', 'no-interaction'])) {
                continue;
            }
            $cliParams .= '--' . $env . '=' . $value . ' ';
        }

        $cliParams = trim($cliParams);
        if ($showAll == false) {
            if (strlen($cliParams) > 1000) {
                $cliParams = substr($cliParams, 0, 1000) . '...(参数过长,省略)';
            }
        }

        if (empty($cliParams)) {
            $cliParams = '(no)';
        }

        return $cliParams;
    }

    /**
     * Registry 只读。
     */
    private function registry(): ProcessRegistry
    {
        return $this->manager->getRegistry();
    }
}
