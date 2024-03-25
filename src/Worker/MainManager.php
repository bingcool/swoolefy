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

namespace Swoolefy\Worker;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\SystemEnv;
use Swoolefy\Exception\WorkerException;
use Swoolefy\Worker\Dto\MessageDto;
use Swoolefy\Core\Table\TableManager;
use Swoolefy\Core\Memory\SysvmsgManager;
use Swoolefy\Core\Process\AbstractProcess;
use RuntimeException;

class MainManager
{

    use Traits\SingletonTrait, Traits\SystemTrait, Traits\MainProcessCommandTrait;

    /**
     * @var array
     */
    protected $config;

    /**
     * @var array
     */
    protected $defaultCoroutineSetting = [
        'enable_deadlock_check' => false
    ];

    /**
     * @var array
     */
    private $processLists = [];

    /**
     * @var array
     */
    private $processWorkers = [];

    /**
     * @var array
     */
    private $processPidMap = [];

    /**
     * @var array
     */
    private $processStatusList = [];

    /**
     * @var int
     */
    private $masterPid;

    /**
     * @var int
     */
    private $masterWorkerId = 0;

    /**
     * @var array
     */
    private $signal = [];

    /**
     * @var bool
     */
    private $isDaemon = false;

    /**
     * @var bool
     */
    private $isExit = false;

    /**
     * @var
     */
    private $startTime;

    /**
     * @var bool
     */
    private $isRunning = false;

    /**
     * @var bool
     */
    private $enablePipe = true;

    /**
     * @var
     */
    private $cliPipeFd;


    /**
     * @var \Closure
     */
    public $onStart;

    /**
     * @var \Closure
     */
    public $onPipeMsg;

    /**
     * @var \Closure
     */
    public $onProxyMsg;

    /**
     * @var \Closure
     */
    public $onCliMsg;

    /**
     * @var \Closure
     */
    public $onCreateDynamicProcess;

    /**
     * @var \Closure
     */
    public $onDestroyDynamicProcess;

    /**
     * @var \Closure
     */
    public $onReportStatus;

    /**
     * @var \Closure
     */
    public $onHandleException;

    /**
     * @var \Closure
     */
    public $onExit;

    /**
     * @var \Closure
     */
    public $onRegisterLogger;

    /**
     * @var \Closure
     */
    private $onRegisterShutdownFunction;

    /**
     * @var \Closure
     */
    protected $closure;

    /**
     * @var string
     */
    protected static $confPath;

    /**
     * @var int
     */
    const NUM_PEISHU = 8;

    /**
     * @var int
     */
    const REPORT_STATUS_TICK_TIME = 5;

    /**
     * @var string
     */
    const MASTER_WORKER_NAME = 'master_worker';

    /**
     * @var string
     */
    const CREATE_DYNAMIC_PROCESS_WORKER = 'create_dynamic_process_worker';

    /**
     * @var string
     */
    const DESTROY_DYNAMIC_PROCESS_WORKER = 'destroy_dynamic_process_worker';

    /**
     * @var string
     */
    const REBOOT_PROCESS_WORKER = 'reboot_process_worker';

    /**
     * ProcessManager constructor
     *
     * @param array $config
     * @param mixed ...$args
     */
    public function __construct(array $config = [], ...$args)
    {
        $this->config = $config;
        $this->setCoroutineSetting(array_merge($this->defaultCoroutineSetting, $config['coroutine_setting'] ?? []));
        $this->onHandleException = function (\Throwable $exception) {
        };
    }

    /**
     * addProcess
     * @param string $process_name
     * @param string $process_class
     * @param int $process_worker_num
     * @param bool $async
     * @param array $args
     * @param mixed $extend_data
     * @param bool $enable_coroutine
     */
    public function addProcess(
        string $process_name,
        string $process_class,
        int    $process_worker_num = 1,
        bool   $async = true,
        array  $args = [],
        ?array $extend_data = null,
        bool   $enable_coroutine = true
    )
    {
        $key = md5($process_name);
        if (isset($this->processLists[$key])) {
            throw new WorkerException("【Error】You can not add the same process={$process_name}");
        }
        if (!$enable_coroutine) {
            $enable_coroutine = true;
        }
        if (!$async) {
            $async = true;
        }

        $maxProcessNum = $this->getMaxProcessNum();

        if (isset($args['max_process_num']) && $args['max_process_num'] > $maxProcessNum) {
            $args['max_process_num'] = $maxProcessNum;
        } else {
            $args['max_process_num'] = $maxProcessNum;
        }

        if ($process_worker_num > $maxProcessNum) {
            $this->fmtWriteInfo("Process Name={$process_name}, params of process_worker_num more then max_process_num={$maxProcessNum}");
            $process_worker_num = $maxProcessNum;
        }
        $this->setProcessLists($process_name, $process_class, $process_worker_num, $args, $extend_data);
    }

    /**
     * @param string $processName
     * @param string $processClass
     * @param int $processWorkerNum
     * @param array $args
     * @param array $extendData
     * @return void
     */
    protected function setProcessLists(
        string $processName,
        string $processClass,
        int $processWorkerNum,
        array $args,
        array $extendData
    )
    {
        $key = md5($processName);
        $this->processLists[$key] = [
            'process_name'       => $processName,
            'process_class'      => $processClass,
            'process_worker_num' => $processWorkerNum,
            'async'              => true,
            'args'               => $args,
            'extend_data'        => $extendData,
            'enable_coroutine'   => true
        ];
    }

    /**
     * setting model add process
     *
     * @param array $conf
     *
     */
    public function loadConf(array $conf)
    {
        foreach($conf as $config)
        {
            $async            = true;
            $enableCoroutine  = true;
            $processName      = $config['process_name'];
            $processClass     = $config['handler'];
            $processWorkerNum = $config['worker_num'] ?? 1;
            if (SystemEnv::isCronService()) {
                $processWorkerNum = 1;
            }
            $args             = $config['args'] ?? [];
            $this->parseArgs($args, $config);
            $extendData = $config['extend_data'] ?? [];
            $this->addProcess($processName, $processClass, $processWorkerNum, $async, $args, $extendData, $enableCoroutine);
        }

        return $this;
    }

    /**
     * @param array $args
     * @param array $config
     */
    protected function parseArgs(array &$args, array $config)
    {
        $args['max_handle']              = $config['max_handle'] ?? 10000;
        $args['life_time']               = $config['life_time'] ?? 3600;
        $args['limit_run_coroutine_num'] = $config['limit_run_coroutine_num'] ?? null;
        $args['description']             = $config['description'] ?? '';
    }

    /**
     * start
     * @return mixed
     */
    public function start()
    {
        try {
            if (!empty($this->processLists)) {
                $this->installErrorHandler();
                $this->setMasterPid(posix_getpid());
                $this->installReportStatus();
                $this->initStart();
                $this->setRunning();
                $this->installCliPipe();
                $this->installSigchldSignal();
                $this->installMasterStopSignal();
                $this->installMasterReloadSignal();
                $this->installRegisterShutdownFunction();
                $this->installSignal();
                $this->swooleEventAdd();
                $this->setStartTime();
            }
            // set process start after
            $masterPid = $this->getMasterPid();
            $this->saveMasterPidToFile($masterPid);
            $this->saveStatusToFile();
            if ($masterPid && is_callable($this->onStart)) {
                $this->onStart && $this->onStart->call($this, $masterPid);
            }
            return $masterPid;
        } catch (\Throwable $throwable) {
            $this->onHandleException->call($this, $throwable);
        }

    }

    /**
     * initStart
     * @return void
     */
    private function initStart()
    {
        foreach ($this->processLists as $key => $list) {
            $processWorkerNum = $list['process_worker_num'] ?? 1;
            for ($workerId = 0; $workerId < $processWorkerNum; $workerId++) {
                try {
                    $processName     = $list['process_name'];
                    $processClass    = $list['process_class'];
                    $async           = $list['async'] ?? true;
                    $args            = $list['args'] ?? [];
                    $extendData      = $list['extend_data'] ?? null;
                    $enableCoroutine = $list['enable_coroutine'] ?? true;
                    /**
                     * @var AbstractWorkerProcess $process
                     */
                    $process = new $processClass(
                        $processName,
                        $async,
                        $args,
                        $extendData,
                        $enableCoroutine
                    );
                    $process->setProcessWorkerId($workerId);
                    $process->setMasterPid($this->masterPid);
                    $process->setStartTime();
                    if (!isset($this->processWorkers[$key][$workerId])) {
                        $this->processWorkers[$key][$workerId] = $process;
                    }
                    usleep(50000);
                } catch (\Throwable $throwable) {
                    $this->onHandleException->call($this, $throwable);
                }
            }
        }

        foreach ($this->processWorkers as $workers) {
            foreach ($workers as $process) {
                $process->start();
                usleep(50000);
            }
        }
    }

    /**
     * @param array $setting
     * @return void
     */
    public function setCoroutineSetting(array $setting)
    {
        $setting['hook_flags'] = $this->getHookFlags($this->config['coroutine_setting']['hook_flags'] ?? '');
        $setting = array_merge(\Swoole\Coroutine::getOptions() ?? [], $setting);
        !empty($setting) && \Swoole\Coroutine::set($setting);
    }

    /**
     * 主进程注册监听退出信号,逐步发送退出指令至子进程退出，子进程完全退出后，master进程最后退出
     * 每个子进程收到退出指令后，等待wait_time后正式退出，那么在这个wait_time过程
     * 子进程逻辑应该通过$this->isRebooting() || $this->isExiting()判断是否在退出状态中，这个状态中不能再处理新的任务数据
     */
    private function installMasterStopSignal()
    {
        if (!$this->isDaemon) {
            // Ctrl+C
            \Swoole\Process::signal(SIGHUP, $this->signalHandle());
            return;
        }
        \Swoole\Process::signal(SIGTERM, $this->signalHandle());
    }

    /**
     * master进程退出处理函数
     *
     * @return \Closure
     */
    private function signalHandle()
    {
        return function ($signal) {
            switch ($signal) {
                case SIGINT:
                case SIGHUP:
                case SIGTERM:
                    if (!$this->isExit) {
                        $this->isExit = true;
                        foreach ($this->processWorkers as $processes) {
                            foreach ($processes as $workerId => $process) {
                                try {
                                    $processName = $process->getProcessName();
                                    $this->writeByProcessName($processName, AbstractBaseWorker::WORKERFY_PROCESS_EXIT_FLAG, $workerId);
                                } catch (\Throwable $exception) {
                                    $this->fmtWriteError("Master handle Signal (SIGINT,SIGTERM) error Process={$processName},worker_id={$workerId} exit failed, error=" . $exception->getMessage());
                                }
                            }
                        }
                        call_user_func($this->onRegisterShutdownFunction);
                        $this->isExit = false;
                        if (method_exists(AbstractProcess::getProcessInstance(), '__destruct') && version_compare(phpversion(), '8.0.0', '>=') ) {
                            AbstractProcess::getProcessInstance()->__destruct();
                        }
                        \Swoole\Event::del(AbstractProcess::getProcessInstance()->getProcess()->pipe);
                        \Swoole\Event::exit();
                        AbstractProcess::getProcessInstance()->getProcess()->exit(0);
                    }
                    break;
                default:
                    break;
            }
        };
    }

    /**
     * 父进程的status通过fifo有名管道信号回传
     *
     * @param string $ctlPipeFile
     * @return void
     */
    private function masterStatusToCliFifoPipe(string $ctlPipeFile)
    {
        $ctlPipe = fopen($ctlPipeFile, 'w+');
        $masterInfo = $this->statusInfoFormat(
            $this->getMasterWorkerName(),
            $this->getMasterWorkerId(),
            $this->getMasterPid(),
            'running',
            $this->startTime
        );
        $separator = $this->getSeparator();
        fwrite($ctlPipe,'Master Process Runtime:'.$separator);
        fwrite($ctlPipe,str_repeat('-',50).$separator);
        fwrite($ctlPipe, $masterInfo,null);
        fwrite($ctlPipe,str_repeat('-',50).$separator.$separator);
        fwrite($ctlPipe,'Children Process Runtime:'.$separator);
        foreach ($this->processWorkers as $processes) {
            ksort($processes);
            /** @var AbstractBaseWorker $process */
            foreach ($processes as $process) {
                $processName = $process->getProcessName();
                $workerId    = $process->getProcessWorkerId();
                $pid         = $process->getPid();
                $startTime   = $process->getStartTime();
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
                    $this->rebootOrExitHandle();
                    $status = 'running';
                } else {
                    $status = 'stop';
                }
                $info = $this->statusInfoFormat(
                    $processName,
                    $workerId,
                    $pid,
                    $status,
                    $startTime,
                    $rebootCount,
                    $processType
                );
                @fwrite($ctlPipe, $info, null);
                if ($status == 'stop') {
                    $this->fmtWriteInfo($info);
                }
            }
            unset($processes);
        }
        @fclose($ctlPipe);
    }

    /**
     * 主进程注册监听自定义的SIGUSR2作为通知子进程重启的信号
     * 每个子进程收到重启指令后，等待wait_time后正式退出，那么在这个wait_time过程
     * 子进程逻辑应该通过$this->isRebooting() || $this->isExiting()判断是否在重启状态中，这个状态中不能再处理新的任务数据
     *
     * @return void
     */
    private function installMasterReloadSignal()
    {
        \Swoole\Process::signal(SIGUSR2, function ($signo) {
            $this->isExit = false;
            foreach ($this->processWorkers as $processes) {
                foreach ($processes as $workerId => $process) {
                    $processName = $process->getProcessName();
                    $this->writeByProcessName($processName, AbstractBaseWorker::WORKERFY_PROCESS_REBOOT_FLAG, $workerId);
                }
            }
        });
    }

    /**
     * installSigchldSignal 注册回收子进程信号
     *
     * @return void
     */
    private function installSigchldSignal()
    {
        \Swoole\Process::signal(SIGCHLD, function ($signo) {
            $this->rebootOrExitHandle();
        });
    }

    /**
     * @param string $name
     * @return void
     */
    public function setCliMasterName(string $name = '')
    {
        $this->closure = function () use ($name) {
            if ($name) {
                cli_set_process_title($name);
            }
        };
    }

    /**
     * checkMasterToExit
     * @return bool
     */
    protected function checkMasterToExit() {
        if(isWorkerService()) {
            return true;
        }
    }

    /**
     * rebootOrExitHandle 子进程退出时，父进程接收的信号处理函数
     *
     * @return void
     */
    protected function rebootOrExitHandle()
    {
        // non block model
        while ($ret = \Swoole\Process::wait(false)) {
            if (!is_array($ret) || !isset($ret['pid'])) {
                $this->fmtWriteError("Swoole\Process::wait error");
                return;
            }
            $pid  = $ret['pid'];
            $code = $ret['code'];

            try {
                switch ($code) {
                    // exit
                    case 0       :
                    case SIGTERM :
                    case SIGKILL :
                        /**@var AbstractBaseWorker $process */
                        $process         = $this->getProcessByPid($pid);
                        if (!is_object($process)) {
                            return;
                        }
                        $processName     = $process->getProcessName();
                        $processWorkerId = $process->getProcessWorkerId();
                        $key = md5($processName);
                        if (isset($this->processWorkers[$key][$processWorkerId])) {
                            unset($this->processWorkers[$key][$processWorkerId]);
                            if (count($this->processWorkers[$key]) == 0) {
                                unset($this->processWorkers[$key]);
                            }
                        }
                        @\Swoole\Event::del($process->getSwooleProcess()->pipe);
                        $this->checkMasterToExit();
                        break;

                    // SIGUSR1作为重启信号
                    case SIGUSR1 :
                    default :
                        $this->rebootWorker($pid);
                        break;
                }
            } catch (\Throwable $throwable) {
                $this->onHandleException->call($this, $throwable);
            }
        }
    }

    /**
     * @param int $pid
     * @return void
     */
    private function rebootWorker(int $pid)
    {
        $process            = $this->getProcessByPid($pid);
        $processName        = $process->getProcessName();
        $processType        = $process->getProcessType();
        $processWorkerId    = $process->getProcessWorkerId();
        $processRebootCount = $process->getRebootCount() + 1;
        $key                = md5($processName);
        $list               = $this->processLists[$key];
        @\Swoole\Event::del($process->getSwooleProcess()->pipe);
        if (isset($this->processWorkers[$key][$processWorkerId])) {
            unset($this->processWorkers[$key][$processWorkerId]);
        }
        try {
            $processName     = $list['process_name'];
            $processClass    = $list['process_class'];
            $async           = $list['async'] ?? true;
            $args            = $list['args'] ?? [];
            $extendData      = $list['extend_data'] ?? null;
            $enableCoroutine = $list['enable_coroutine'] ?? true;
            /** @var AbstractBaseWorker $newProcess */
            $newProcess = new $processClass(
                $processName,
                $async,
                $args,
                $extendData,
                $enableCoroutine
            );
            $newProcess->setProcessWorkerId($processWorkerId);
            $newProcess->setMasterPid($this->masterPid);
            $newProcess->setProcessType($processType);
            $newProcess->setRebootCount($processRebootCount);
            $newProcess->setStartTime();
            $this->processWorkers[$key][$processWorkerId] = $newProcess;
            $newProcess->start();
            $this->swooleEventAdd($newProcess);
        } catch (\Throwable $throwable) {
            if (isset($this->processWorkers[$key][$processWorkerId])) {
                unset($this->processWorkers[$key][$processWorkerId]);
            }
            $this->onHandleException->call($this, $throwable);
        }
    }

    /**
     * @param AbstractBaseWorker|null $currentProcess
     * @return mixed
     */
    private function swooleEventAdd(?AbstractBaseWorker $currentProcess = null)
    {
        $processWorkers = [];
        if (isset($currentProcess)) {
            $processName                            = $currentProcess->getProcessName();
            $processWorkerId                        = $currentProcess->getProcessWorkerId();
            $key                                    = md5($processName);
            $processWorkers[$key][$processWorkerId] = $currentProcess;
        } else {
            $processWorkers = $this->processWorkers;
        }

        foreach ($processWorkers as $processes) {
            foreach ($processes as $process) {
                /**
                 * @var \Swoole\Process $swooleProcess
                 */
                $swooleProcess = $process->getSwooleProcess();
                \Swoole\Event::add($swooleProcess->pipe, function ($pipe) use ($swooleProcess) {
                    $message = $swooleProcess->read(64 * 1024);
                    if (is_string($message)) {
                        $messageDto = unserialize($message);
                        if (!$messageDto instanceof MessageDto) {
                            $this->fmtWriteError("Accept message type error");
                            return;
                        } else {
                            $msg                 = $messageDto->data;
                            $fromProcessName     = $messageDto->fromProcessName;
                            $fromProcessWorkerId = $messageDto->fromProcessWorkerId;
                            $toProcessName       = $messageDto->toProcessName;
                            $toProcessWorkerId   = $messageDto->toProcessWorkerId;
                        }
                    }

                    if (isset($msg) && isset($fromProcessName) && isset($fromProcessWorkerId) && isset($toProcessName) && isset($toProcessWorkerId)) {
                        try {
                            if ($toProcessName == $this->getMasterWorkerName()) {
                                $action           = $msg['action'] ?? '';
                                $processName      = $msg['process_name'] ?? '';
                                $data             = $msg['data'] ?? [];
                                $actionHandleFlag = false;
                                if ($action && $processName) {
                                    switch ($action) {
                                        case MainManager::CREATE_DYNAMIC_PROCESS_WORKER :
                                            $actionHandleFlag   = true;
                                            $dynamicProcessName = $processName;
                                            $dynamicProcessNum  = $data['dynamic_process_num'] ?? 1;
                                            if (is_callable($this->onCreateDynamicProcess)) {
                                                $this->onCreateDynamicProcess->call($this, $dynamicProcessName, $dynamicProcessNum, $fromProcessName, $fromProcessWorkerId);
                                            } else {
                                                $this->createDynamicProcess($dynamicProcessName, $dynamicProcessNum);
                                            }
                                            break;
                                        case MainManager::DESTROY_DYNAMIC_PROCESS_WORKER:
                                            $actionHandleFlag   = true;
                                            $dynamicProcessName = $processName;
                                            $dynamicProcessNum  = $data['dynamic_process_num'] ?? -1;
                                            if (is_callable($this->onDestroyDynamicProcess)) {
                                                $this->onDestroyDynamicProcess->call($this, $dynamicProcessName, $dynamicProcessNum, $fromProcessName, $fromProcessWorkerId);
                                            } else {
                                                $this->destroyDynamicProcess($dynamicProcessName);
                                            }
                                            break;
                                        case MainManager::REBOOT_PROCESS_WORKER:
                                            $actionHandleFlag = true;
                                            $pid = $data['worker_pid'];
                                            $this->rebootWorker($pid);
                                            break;
                                        case AbstractBaseWorker::WORKERFY_PROCESS_STATUS_FLAG:
                                            $actionHandleFlag       = true;
                                            $workerId               = $data['worker_id'];
                                            $status                 = $data['status'] ?? [];
                                            $status['process_name'] = $processName;
                                            $status['worker_id']    = $workerId;
                                            $this->processStatusList[$processName][$workerId] = $status;
                                            break;
                                        default:
                                            break;
                                    }
                                }
                                if ($actionHandleFlag === false) {
                                    if (is_callable($this->onPipeMsg)) {
                                        $this->onPipeMsg->call($this, $msg, $fromProcessName, $fromProcessWorkerId);
                                    } else {
                                        $this->writeByProcessName($fromProcessName, $msg, $fromProcessWorkerId);
                                    }
                                }
                            } else {
                                if (is_callable($this->onProxyMsg)) {
                                    $this->onProxyMsg->call($this, $msg, $fromProcessName, $fromProcessWorkerId, $toProcessName, $toProcessWorkerId);
                                } else {
                                    $this->writeByMasterProxy($msg, $fromProcessName, $fromProcessWorkerId, $toProcessName, $toProcessWorkerId);
                                }
                            }
                        } catch (\Throwable $throwable) {
                            $this->onHandleException->call($this, $throwable);
                        }
                    }
                });
            }
        }

    }

    /**
     * @param int $worker_master_pid
     * @return void
     */
    public function saveMasterPidToFile(int $worker_master_pid)
    {
        @file_put_contents(WORKER_PID_FILE, $worker_master_pid);
    }

    /**
     * @param array $status
     * @return void
     */
    public function saveStatusToFile(array $status = [])
    {
        if (empty($status)) {
            $status = $this->getProcessStatus();
        }
        @file_put_contents(WORKER_STATUS_FILE, json_encode($status, JSON_UNESCAPED_UNICODE));
    }

    /**
     * dynamicCreateProcess
     *
     * @param string $process_name
     * @param int $process_num
     * @return mixed
     * @throws WorkerException
     */
    public function createDynamicProcess(string $process_name, int $process_num = 2)
    {
        if ($this->isMasterExiting()) {
            $this->fmtWriteInfo("Master process is exiting now，forbidden to create dynamic process");
            return false;
        }

        $key = md5($process_name);
        // 初始更新存贮动态进程数量
        $this->storageDynamicProcessNum($process_name);
        if ($this->processLists[$key]['dynamic_process_destroying'] ?? false) {
            $msg = "【Warning】 Process name={$process_name} is exiting now，forbidden to create dynamic process, please try again after moment";
            throw new WorkerException($msg);
        }

        if ($process_num <= 0) {
            $process_num = 1;
        }

        $processWorkerNum = $this->processLists[$key]['process_worker_num'];
        $process_name     = $this->processLists[$key]['process_name'];
        $processClass     = $this->processLists[$key]['process_class'];
        if (isset($this->processLists[$key]['dynamic_process_worker_num']) && $this->processLists[$key]['dynamic_process_worker_num'] > 0) {
            $totalProcessNum = $processWorkerNum + $this->processLists[$key]['dynamic_process_worker_num'] + $process_num;
        } else {
            $totalProcessNum = $processWorkerNum + $process_num;
            $this->processLists[$key]['dynamic_process_worker_num'] = 0;
        }

        if ($totalProcessNum > $this->processLists[$key]['args']['max_process_num']) {
            $totalProcessNum = $this->processLists[$key]['args']['max_process_num'];
        }
        $async                   = $this->processLists[$key]['async'];
        $args                    = $this->processLists[$key]['args'];
        $extendData              = $this->processLists[$key]['extend_data'];
        $enableCoroutine         = $this->processLists[$key]['enable_coroutine'];
        $runningProcessWorkerNum = $processWorkerNum + $this->processLists[$key]['dynamic_process_worker_num'];

        if ($runningProcessWorkerNum >= $totalProcessNum) {
            $msg = "【Warning】 Children process num={$totalProcessNum}, achieve max_process_num，forbidden to create process";
            throw new WorkerException($msg);
        }

        for ($workerId = $runningProcessWorkerNum; $workerId < $totalProcessNum; $workerId++) {
            $this->forkNewProcess($processClass, $process_name, $workerId, $args, $extendData);
        }
        // 创建动态进程后,更新存贮动态进程数量
        $this->storageDynamicProcessNum($process_name);
    }

    /**
     *
     * @param $processClass
     * @param $processName
     * @param $workerId
     * @param $args
     * @param $extendData
     * @return void
     */
    protected function forkNewProcess($processClass, $processName, $workerId, $args = [], $extendData = [])
    {
        try {
            /** @var AbstractBaseWorker $newProcess */
            $newProcess = new $processClass(
                $processName,
                true,
                $args,
                $extendData,
                true
            );
            $key = md5($processName);
            $newProcess->setProcessWorkerId($workerId);
            $newProcess->setMasterPid($this->getMasterPid());
            $newProcess->setProcessType(AbstractBaseWorker::PROCESS_STATIC_TYPE_NAME);
            $newProcess->setStartTime();
            $this->processWorkers[$key][$workerId] = $newProcess;
            $newProcess->start();
            $this->swooleEventAdd($newProcess);
            $this->fmtWriteInfo("Process name={$processName},worker_id={$workerId} create successful");
        } catch (\Throwable $throwable) {
            unset($this->processWorkers[$key][$workerId], $newProcess);
            $this->onHandleException->call($this, $throwable);
        }
    }

    /**
     * destroyDynamicProcess
     *
     * @param string $process_name
     * @param int $process_num
     * @return void
     * @throws WorkerException
     */
    public function destroyDynamicProcess(string $process_name, int $process_num = -1)
    {
        $processWorkers = $this->getProcessByName($process_name, -1);
        $key = md5($process_name);
        foreach ($processWorkers as $workerId => $process) {
            if ($process->isDynamicProcess()) {
                $this->processLists[$key]['dynamic_process_destroying'] = true;
                try {
                    $this->writeByProcessName($process_name, AbstractBaseWorker::WORKERFY_PROCESS_EXIT_FLAG, $workerId);
                    if ($this->processLists[$key]['dynamic_process_worker_num'] > 0) {
                        $this->processLists[$key]['dynamic_process_worker_num']--;
                    }
                    $this->fmtWriteInfo("Dynamic process={$process_name},worker_id={$workerId} destroy successful");
                } catch (\Throwable $e) {
                    $this->fmtWriteError("DestroyDynamicProcess error message=" . $e->getMessage());
                }
            }
        }
        $this->processLists[$key]['dynamic_process_destroying'] = false;
    }

    /**
     * storageDynamicProcessNum
     *
     * @param string $process_name
     * @return int
     * @throws WorkerException
     */
    public function storageDynamicProcessNum(string $process_name)
    {
        $dynamicProcessNum = 0;
        $key = md5($process_name);
        $processWorkers = $this->getProcessByName($process_name, -1);
        foreach ($processWorkers as $process) {
            if ($process->isDynamicProcess()) {
                ++$dynamicProcessNum;
            }
        }

        $this->processLists[$key]['dynamic_process_worker_num'] = $dynamicProcessNum;

        return $dynamicProcessNum;
    }

    /**
     * @return int
     */
    public function getMasterPid()
    {
        return $this->masterPid;
    }

    /**
     * @param string $process_name
     * @return bool
     */
    public function isMaster(string $process_name)
    {
        if ($process_name == $this->getMasterWorkerName()) {
            return true;
        }
        return false;
    }

    /**
     * getProcessStatus
     *
     * @param int $running_status
     * @return array
     */
    public function getProcessStatus(int $running_status = 1)
    {
        $status = [];
        $childrenNum = 0;
        foreach ($this->processWorkers as $processes) {
            $childrenNum += count($processes);
            ksort($processes);
            /**
             * @var AbstractBaseWorker $process
             */
            foreach ($processes as $process) {
                $processName = $process->getProcessName();
                $workerId = $process->getProcessWorkerId();
                $this->writeByProcessName($processName, AbstractBaseWorker::WORKERFY_PROCESS_STATUS_FLAG, $workerId);
            }
        }

        $cpuNum          = swoole_cpu_num();
        $phpVersion      = PHP_VERSION;
        $swooleVersion   = swoole_version();
        $enableCliPipe   = is_resource($this->cliPipeFd) ? 1 : 0;
        $swooleTableInfo = $this->getSwooleTableInfo(false);
        $cliParams       = $this->getOptionParams(true);
        $hostName        = gethostname();
        list($msgSysvmsgInfo, $sysKernel) = $this->getSysvmsgInfo();

        $status['master'] = [
            'start_script_file'  => WORKER_START_SCRIPT_FILE,
            'pid_file'           => WORKER_PID_FILE,
            'running_status'     => $running_status,
            'cli_params'         => $cliParams,
            'master_pid'         => $this->getMasterPid(),
            'cpu_num'            => $cpuNum,
            'memory'             => Helper::getMemoryUsage(),
            'php_version'        => $phpVersion,
            'swoole_version'     => $swooleVersion,
            'enable_cli_pipe'    => $enableCliPipe,
            'hostname'           => $hostName,
            'msg_sysvmsg_kernel' => $sysKernel,
            'msg_sysvmsg_info'   => $msgSysvmsgInfo,
            'swoole_table_info'  => $swooleTableInfo,
            'children_num'       => $childrenNum,
            'children_process'   => [],
            'stop_time'          => !$running_status ? date("Y-m-d H:i:s") : '',
            'report_time'        => date("Y-m-d H:i:s")
        ];

        $runningChildrenNum = 0;
        $childrenStatus = [];
        foreach ($this->processWorkers as $processes) {
            ksort($processes);
            foreach ($processes as $process) {
                /**
                 * @var AbstractBaseWorker $process
                 */
                $processName = $process->getProcessName();
                $workerId    = $process->getProcessWorkerId();
                $pid         = $process->getPid();
                $startTime   = $process->getStartTime();
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
                    // loop report should be handed (exit) some deal process
                    $this->rebootOrExitHandle();
                    $processStatus = 'running';
                    $childrenStatus[$processName][$workerId] = [
                        'process_name' => $processName,
                        'worker_id'    => $workerId,
                        'pid'          => $pid,
                        'process_type' => $processType,
                        'start_time'   => $startTime,
                        'reboot_count' => $rebootCount,
                        'status'       => $processStatus,
                        'runtime'      => $this->processStatusList[$processName][$workerId] ?? []
                    ];
                    $runningChildrenNum++;
                }
            }
            $status['master']['children_process'] = $childrenStatus;
            unset($processes);
        }

        if (empty($status['master']['children_process'])) {
            foreach ($this->processStatusList as $processName => $item) {
                foreach ($item as $workerId => $runtime) {
                    $status['master']['children_process'][$processName][$workerId]['runtime'] = $runtime;
                }
            }
        }
        $status['master']['children_num'] = $runningChildrenNum;
        return $status;
    }

    /**
     * installReportStatus
     * @return void
     */
    private function installReportStatus()
    {
        $defaultTickTime = self::REPORT_STATUS_TICK_TIME;

        if (isset($this->config['report_status_tick_time'])) {
            $tickTime = $this->config['report_status_tick_time'];
        } else {
            $tickTime = $defaultTickTime;
        }

        if ($tickTime < $defaultTickTime) {
            $tickTime = $defaultTickTime;
        }

        // 必须设置不使用协程，否则master进程存在异步IO,后面子进程reboot()时
        // 出现unable to create Swoole\Process with async-io threads
        $timerId = \Swoole\Timer::tick($tickTime * 1000, function () {
            try {
                $status = $this->getProcessStatus();
                // save status
                file_put_contents(WORKER_STATUS_FILE, json_encode($status, JSON_UNESCAPED_UNICODE));
                // callable todo
                if (is_callable($this->onReportStatus)) {
                    $this->onReportStatus->call($this, $status);
                }
            } catch (\Throwable $throwable) {
                $this->onHandleException->call($this, $throwable);
            }
        });

        // master destroy before clear timer_id
        if ($timerId) {
            register_shutdown_function(function () use ($timerId) {
                \Swoole\Timer::clear($timerId);
            });
        }
    }

    /**
     * getProcessByName
     * @param string $process_name
     * @param int $process_worker_id
     * @return mixed|null
     */
    public function getProcessByName(string $process_name, int $process_worker_id = 0)
    {
        $key = md5($process_name);
        if (isset($this->processWorkers[$key][$process_worker_id])) {
            return $this->processWorkers[$key][$process_worker_id];
        } else if ($process_worker_id < 0) {
            return $this->processWorkers[$key];
        } else {
            throw new WorkerException("Missing and not found process_name={$process_name}, worker_id={$process_worker_id}");
        }
    }

    /**
     * getProcessByPid
     * @param int $pid
     * @return mixed
     */
    public function getProcessByPid(int $pid)
    {
        $p = null;
        foreach ($this->processWorkers as $processes) {
            foreach ($processes as $process) {
                if ($process->getPid() == $pid) {
                    $p = $process;
                    break;
                }
            }
            if ($p) {
                break;
            }
        }
        return $p;
    }

    /**
     * @param string $process_name
     * @param int $process_worker_id
     * @return mixed
     */
    public function getPidByName(string $process_name, int $process_worker_id)
    {
        $process = $this->getProcessByName($process_name, $process_worker_id);
        return is_object($process) ? $process->getPid() : null;
    }

    /**
     * getProcessWorkerId
     * @return int
     */
    public function getMasterWorkerId(): int
    {
        return $this->masterWorkerId;
    }

    /**
     * getMasterWorkerName
     * @return string
     */
    public function getMasterWorkerName(): string
    {
        return MainManager::MASTER_WORKER_NAME;
    }

    /**
     * isMasterExiting
     * @return bool
     */
    public function isMasterExiting(): bool
    {
        return $this->isExit;
    }

    /**
     * @param string $process_name
     * @param mixed $data
     * @param int $process_worker_id
     * @return bool
     */
    public function writeByProcessName(string $process_name, $data, int $process_worker_id = 0)
    {
        if ($this->isMaster($process_name)) {
            throw new WorkerException("Master process can not write msg to master process self");
        }

        if (!$this->isRunning()) {
            throw new WorkerException("Master process is not start, you can not use writeByProcessName(), please checkout it");
        }

        $processWorkers = [];
        $process = $this->getProcessByName($process_name, $process_worker_id);
        if (is_object($process) && $process instanceof AbstractBaseWorker) {
            $processWorkers = [$process_worker_id => $process];
        } else if (is_array($process)) {
            $processWorkers = $process;
        }

        $messageDto                      = new MessageDto();
        $messageDto->fromProcessName     = $this->getMasterWorkerName();
        $messageDto->fromProcessWorkerId = $this->getMasterWorkerId();
        $messageDto->data                = $data;
        $messageDto->isProxy             = false;
        $message = serialize($messageDto);
        foreach ($processWorkers as $process) {
            $process->getSwooleProcess()->write($message);
        }
    }

    /**
     * master proxy worker message
     * @param mixed $data
     * @param string $from_process_name
     * @param int $from_process_worker_id
     * @param string $to_process_name
     * @param int $to_process_worker_id
     * @return bool
     */
    public function writeByMasterProxy(
        $data,
        string $from_process_name,
        int $from_process_worker_id,
        string $to_process_name,
        int $to_process_worker_id
    )
    {
        if ($this->isMaster($to_process_name)) {
            return false;
        }

        $processWorkers = [];
        $process = $this->getProcessByName($to_process_name, $to_process_worker_id);
        if (is_object($process) && $process instanceof AbstractBaseWorker) {
            $processWorkers = [$to_process_worker_id => $process];
        } else if (is_array($process)) {
            $processWorkers = $process;
        }

        $messageDto                      = new MessageDto();
        $messageDto->fromProcessName     = $from_process_name;
        $messageDto->fromProcessWorkerId = $from_process_worker_id;
        $messageDto->data                = $data;
        $messageDto->isProxy             = true;
        $message = serialize($messageDto);
        foreach ($processWorkers as $process) {
            $process->getSwooleProcess()->write($message);
        }
    }

    /**
     * broadcast message to all worker
     * @param string $process_name
     * @param mixed $data
     * @return void
     */
    public function broadcastProcessWorker(string $process_name, $data = '')
    {
        $messageDto                      = new MessageDto();
        $messageDto->fromProcessName     = $this->getMasterWorkerName();
        $messageDto->fromProcessWorkerId = $this->getMasterWorkerId();
        $messageDto->data                = $data;
        $messageDto->isProxy             = true;
        $message = serialize($messageDto);
        if ($process_name) {
            $key = md5($process_name);
            if (!isset($this->processWorkers[$key])) {
                $exception = new WorkerException(sprintf(
                    "%s::%s not exist process=%s, please check it",
                    __CLASS__,
                    __FUNCTION__,
                    $process_name
                ));
            }

            $processWorkers = $this->processWorkers[$key];
            foreach ($processWorkers as $process) {
                $process->getSwooleProcess()->write($message);
            }
        }

        if (isset($exception) && $exception instanceof \Throwable) {
            $this->onHandleException->call($this, $exception);
        }
    }

    /**
     * @param int $signal
     * @param callable $function
     * @return void
     */
    public function addSignal(int $signal, callable $function)
    {
        // forbidden over has registered signal
        if (!in_array($signal, [SIGTERM, SIGUSR2, SIGUSR1, SIGCHLD])) {
            $this->signal[$signal] = [$signal, $function];
        }
    }

    /**
     * registerSignal
     * @return void
     */
    private function installSignal()
    {
        if (!empty($this->signal)) {
            foreach ($this->signal as $signalInfo) {
                list($signal, $function) = $signalInfo;
                try {
                    \Swoole\Process::signal($signal, $function);
                } catch (\Throwable $throwable) {
                    $this->onHandleException->call($this, $throwable);
                }
            }
        }
    }

    /**
     * @param bool $enable_pipe
     * @return void
     */
    public function enableCliPipe(bool $enable_pipe = true)
    {
        $this->enablePipe = $enable_pipe;
    }

    /**
     * install Cli Pipe for listen cli command
     * @return bool|null
     * @throws \RuntimeException
     */
    private function installCliPipe()
    {
        if (!$this->enablePipe) {
            return false;
        }

        $cliPipeFile = $this->getCliToWorkerPipeFile();
        if (file_exists($cliPipeFile)) {
            @unlink($cliPipeFile);
        }

        if (!posix_mkfifo($cliPipeFile, 0777)) {
            throw new RuntimeException("Create Cli Pipe failed");
        }

        $this->cliPipeFd = fopen($cliPipeFile, 'w+');
        is_resource($this->cliPipeFd) && stream_set_blocking($this->cliPipeFd, false);
        \Swoole\Event::add($this->cliPipeFd, function () {
            try {
                $pipeMsg = fread($this->cliPipeFd, 8192);
                $cliPipeMsgDto = unserialize($pipeMsg);
                if ($cliPipeMsgDto instanceof \Swoolefy\Worker\Dto\PipeMsgDto) {
                    switch ($cliPipeMsgDto->action) {
                        case WORKER_CLI_STATUS :
                            $this->masterStatusToCliFifoPipe($cliPipeMsgDto->targetHandler);
                            break;
                        case WORKER_CLI_STOP :
                            $this->stopAllWorkerProcessCommand();
                            break;
                        case WORKER_CLI_SEND_MSG :
                            $processName = $cliPipeMsgDto->targetHandler;
                            $key = md5($processName);
                            $receiveMessage = json_decode($cliPipeMsgDto->message, true);
                            $action = $receiveMessage['action'] ?? '';
                            switch ($action) {
                                // 启动指定进程
                                case 'start' :
                                    $key = md5($processName);
                                    if (!isset($this->processLists[$key])) {
                                        $config = $this->parseLoadConf($processName);
                                        if (empty($config)) {
                                            return $this->responseMsgByPipe("找不到进程名【{$processName}】的配置项！");
                                        }
                                        $this->responseMsgByPipe("进程【{$processName}】已开始启动，请留意！");
                                        $this->startWorkerProcessCommand($config);
                                    }else {
                                        if (isset($this->processWorkers[$key])) {
                                            $this->responseMsgByPipe("进程【{$processName}】已存在，请使用restart命令重启！");
                                        }
                                    }
                                    break;
                                // 重启指定进程
                                case 'restart' :
                                    $key = md5($processName);
                                    if (isset($this->processWorkers[$key])) {
                                        $this->responseMsgByPipe("进程【{$processName}】已开始重启，请留意！");
                                        $this->restartWorkerProcessCommand($processName);
                                    }else {
                                        $this->responseMsgByPipe("进程【{$processName}】不存在，请检查进程名称是否正确！");
                                    }
                                    break;
                                // 停止指定进程
                                case 'stop':
                                    $this->responseMsgByPipe("进程【{$processName}】开始逐步停止，请留意！");
                                    $this->stopWorkerProcessCommand($processName);
                                    break;
                                default:
                                    if (isset($this->processWorkers[$key])) {
                                        $processes = $this->processWorkers[$key];
                                        $this->responseMsgByPipe("子进程【{$processName}】已接收到指令，请留意！");
                                        ksort($processes);
                                        foreach ($processes as $process) {
                                            /**
                                             * @var AbstractBaseWorker $process
                                             */
                                            $processName = $process->getProcessName();
                                            $workerId = $process->getProcessWorkerId();
                                            $this->writeByProcessName($processName, $cliPipeMsgDto->message, $workerId);
                                        }
                                    }
                                    break;
                            }
                            break;
                        default:
                            break;
                    }
                }

            } catch (\Throwable $throwable) {
                $this->onHandleException->call($this, $throwable);
            }
        });
    }

    /**
     * addProcessByCli
     * @param string $process_name
     * @param int $num
     * @return void
     */
    private function addProcessByCli(string $process_name, int $num = 1)
    {
        $key = md5($process_name);
        if (isset($this->processLists[$key])) {
            $this->createDynamicProcess($process_name, $num);
        } else {
            $this->fmtWriteInfo("Not exist children_process_name = {$process_name}, so add failed");
        }
    }

    /**
     * removeProcessByCli
     * @param string $process_name
     * @param int $num
     * @return void
     */
    private function removeProcessByCli(string $process_name, int $num = 1)
    {
        $key = md5($process_name);
        if (isset($this->processLists[$key])) {
            $this->destroyDynamicProcess($process_name, $num);
        } else {
            $this->fmtWriteError("Not exist children_process_name = {$process_name}, remove failed");
        }
    }

    /**
     * getCliPipeFile
     * @return string
     */
    public function getCliToWorkerPipeFile()
    {
        return CLI_TO_WORKER_PIPE;
    }

    /**
     * getCliEnvParam
     * @param string $name
     * @return array|false|string|null
     */
    public function getCliEnvParam(string $name)
    {
        $value = @getenv($name);
        if ($value !== false) {
            return $value;
        }
        return null;
    }

    /**
     * installRegisterShutdownFunction
     * @return void
     */
    private function installRegisterShutdownFunction()
    {
        if(!$this->inMasterProcessEnv()) {
            return;
        }

        $this->onRegisterShutdownFunction = function () {
            // children process extends this register_shutdown_function, so ignore for children process
            try {
                // exit handle
                is_callable($this->onExit) && $this->onExit->call($this);

            } catch (\Throwable $throwable) {
                $this->onHandleException->call($this, $throwable);
            } finally {
                // close pipe fifo
                if (is_resource($this->cliPipeFd)) {
                    @\Swoole\Event::del($this->cliPipeFd);
                    fclose($this->cliPipeFd);
                }
                // remove sysvmsg queue
                $sysvmsgManager = SysvmsgManager::getInstance();
                $sysvmsgManager->destroyMsgQueue();
                unset($sysvmsgManager);
                // remove signal
                @\Swoole\Process::signal(SIGUSR1, null);
                @\Swoole\Process::signal(SIGUSR2, null);
                @\Swoole\Process::signal(SIGTERM, null);
            }
            $this->fmtWriteInfo("终端关闭，master进程stop, master_pid={$this->masterPid}");
        };
    }

    /**
     * setMasterPid
     * @return void
     */
    private function setMasterPid(int $masterId)
    {
        $this->masterPid = $masterId;
        if (SystemEnv::isDaemonService()) {
            cli_set_process_title(APP_NAME."-swoolefy-".WORKER_SERVICE_NAME."-php-daemon-master:" . WORKER_START_SCRIPT_FILE);
        }else if (SystemEnv::isCronService()) {
            cli_set_process_title(APP_NAME."-swoolefy-".WORKER_SERVICE_NAME."-php-cron-master:" . WORKER_START_SCRIPT_FILE);
        }else if (SystemEnv::isScriptService()) {
            cli_set_process_title(APP_NAME."-swoolefy-".WORKER_SERVICE_NAME."-php-script-master:" . WORKER_START_SCRIPT_FILE);
        }

        defined('WORKER_MASTER_PID') OR define('WORKER_MASTER_PID', $this->masterPid);
    }

    /**
     * setStartTime
     * @return void
     */
    private function setStartTime()
    {
        $this->startTime = date('Y-m-d H:i:s', strtotime('now'));
    }

    /**
     * flag start
     * @return bool
     */
    protected function setRunning()
    {
        $this->isRunning = true;
    }

    /**
     * master && children process is running status
     * @return bool
     */
    public function isRunning()
    {
        if (isset($this->isRunning) && $this->isRunning === true) {
            return true;
        }
        return false;
    }

    /**
     * getSwooleTableInfo
     * @param bool $simple
     * @return string
     */
    private function getSwooleTableInfo(bool $simple = true)
    {
        $swooleTableInfo = "Disable swoole table (unenabled)";
        $tableManager = TableManager::getInstance();
        if ($simple) {
            // todo
            $allTableName = $tableManager->getAllTableName();
            if (!empty($allTableName) && is_array($allTableName)) {
                $allTableNameStr = implode(',', $allTableName);
                $swooleTableInfo = "[{$allTableNameStr}]";
            }
        } else {
            //todo
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
     * getSysvmsgInfo
     * @return array
     */
    private function getSysvmsgInfo()
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
     * @return float|int
     */
    private function getMaxProcessNum()
    {
        return (swoole_cpu_num()) * (self::NUM_PEISHU);
    }

    /**
     * 实时加载配置文件路径
     *
     * @param string $confPath
     * @return array
     */
    public static function loadConfByPath(string $confPath = '')
    {
        if (!empty(self::$confPath)) {
            $confPath = self::$confPath;
            $conf = include $confPath;
            return $conf;
        }
        self::$confPath = $confPath;
        $conf = include $confPath;
        self::findDuplicateProcessName($confPath, $conf);
        return $conf;
    }

    /**
     * @param string $confPath
     * @param array $conf
     * @return void
     */
    private static function findDuplicateProcessName(string $confPath, array &$conf)
    {
        $processNames = array_column($conf, 'process_name');
        $uniqueProcessNames = array_unique($processNames);
        $duplicateProcessNames = array_diff_assoc($processNames, $uniqueProcessNames);
        if (!empty($duplicateProcessNames)) {
            $processNameStr = implode(',', $duplicateProcessNames);
            fmtPrintError("{$confPath} 存在重复命名的进程[{$processNameStr}],请检查");
            exit(0);
        }
    }

    /**
     * @return void
     */
    protected function tickForkNewProcess()
    {
        $conf = self::loadConfByPath();
        // 新增-启动
        foreach ($conf as &$config) {
            $processName = $config['process_name'];
            $key = md5($processName);
            $config['key'] = $key;
            if (!isset($this->processLists[$key])) {
                $processName      = $config['process_name'];
                $processClass     = $config['handler'];
                if ($config['worker_num'] > $this->getMaxProcessNum()) {
                    $config['worker_num'] = $this->getMaxProcessNum();
                }
                $processWorkerNum = $config['worker_num'] ?? 1;
                $args             = $config['args'] ?? [];
                $extendData       = $config['extend_data'] ?? [];
                $this->parseArgs($args, $config);
                for ($workerId=0; $workerId< $processWorkerNum; $workerId++) {
                    $this->forkNewProcess($processClass, $processName, $workerId, $args, $extendData);
                }
                $this->setProcessLists($processName, $processClass, $processWorkerNum, $args, $extendData);
            }
        }

        $confMap = array_column($conf, null, 'key');
        // 删除-停止
        foreach ($this->processLists as $key=>$item) {
            if (!isset($confMap[$key])) {
                $processes = $this->processWorkers[$key] ?? [];
                foreach ($processes as $workerId => $process) {
                    try {
                        $processName = $process->getProcessName();
                        $this->writeByProcessName($processName, AbstractBaseWorker::WORKERFY_PROCESS_EXIT_FLAG, $workerId);
                    } catch (\Throwable $exception) {
                        $this->fmtWriteError("Reload Command send stop Signal (SIGINT,SIGTERM) error Process={$processName},worker_id={$workerId} exit failed, error=" . $exception->getMessage());
                    }
                }
                unset($this->processLists[$key], $this->processWorkers[$key]);
            }
        }
    }

    /**
     * @param bool $showAll
     * @return string
     */
    private function getOptionParams(bool $showAll = false)
    {
        $cliParams = '';
        $envCliParams = getenv('ENV_CLI_PARAMS') ? json_decode(getenv('ENV_CLI_PARAMS'), true) : [];

        foreach ($envCliParams as $env=>$value) {
            if (in_array($env, ['help','quiet','verbose','version','ansi','no-interaction'])) {
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
     * @param string $process_name
     * @param int $worker_id
     * @param int $pid
     * @param string $status
     * @param string $start_time
     * @param int $reboot_count
     * @param string $process_type
     * @return string
     */
    private function statusInfoFormat(
        string $process_name,
        int $worker_id,
        int $pid,
        string $status,
        string $start_time = '',
        int $reboot_count = 0,
        string $process_type = ''
    )
    {
        if ($process_name == $this->getMasterWorkerName()) {
            $childrenNum = 0;
            foreach ($this->processWorkers as $processes) {
                $childrenNum += count($processes);
            }
            $pid = Swfy::getMasterPid();
            $startScriptFile = WORKER_START_SCRIPT_FILE;
            $pidFile         = WORKER_PID_FILE;
            $cpuNum          = swoole_cpu_num();
            $memory          = Helper::getMemoryUsage();
            $phpVersion      = PHP_VERSION;
            $swooleVersion   = swoole_version();
            $enableCliPipe   = is_resource($this->cliPipeFd) ? 1 : 0;
            list($msgSysvmsgInfo, $sysKernel) = $this->getSysvmsgInfo();
            $swooleTableInfo = $this->getSwooleTableInfo();
            $cliParams       = $this->getOptionParams(false);
            $maxNum          = $this->getMaxProcessNum();
            $hostname        = gethostname();
            $infoItem = [
                'master_name' => $process_name,
                'master_worker_id(default 0)' => $worker_id,
                'swoole_master_pid' => $pid,
                'master_status' => $status,
                'start_time'=> $start_time,
                'cli_option_params' => $cliParams,
                'start_script_file' => $startScriptFile,
                'pid_file' =>  $pidFile,
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
                'hostname'=> $hostname,
            ];
            $formattedData = [];
            foreach ($infoItem as $name=>$value) {
                $formattedData[] = $name.':'.$value;
            }
            $info = implode($this->getSeparator(), $formattedData).$this->getSeparator();
        } else {
            // worker info
            $memory = $this->processStatusList[$process_name][$worker_id]['memory'] ?? '--';
            $info = "【{$process_name}@{$worker_id}】【{$process_type}】: 进程名称name: $process_name, 进程编号worker_id: $worker_id, 进程Pid: $pid, 进程状态status：$status, 启动(重启)时间：$start_time, 内存占用：$memory, reboot次数：$reboot_count";
        }

        return $info;
    }

    /**
     * @return string
     */
    private function getSeparator()
    {
        return PHP_EOL;
    }


}
