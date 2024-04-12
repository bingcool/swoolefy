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

use Swoole\Event;
use Swoole\Process;
use Swoole\Coroutine\Channel;
use Swoolefy\Core\BaseServer;
use Swoolefy\Core\Crontab\CrontabManager;
use Swoolefy\Core\SystemEnv;
use Swoolefy\Exception\WorkerException;
use Swoolefy\Worker\Dto\MessageDto;

/**
 * Class AbstractProcess
 * @package Workerfy
 */
abstract class AbstractBaseWorker
{

    use Traits\SystemTrait;
    use Traits\WorkerProcessCommandTrait;

    /**
     * @var AbstractBaseWorker
     */
    protected static $processInstance;

    /**
     * @var Process
     */
    private $swooleProcess;

    /**
     * @var string
     */
    private $processName;

    /**
     * @var bool
     */
    private $async = true;

    /**
     * @var array
     */
    private $args = [];

    /**
     * @var null
     */
    private $extendData;

    /**
     * @var bool
     */
    private $enableCoroutine = false;

    /**
     * @var resource
     */
    private $cliPipeFd;

    /**
     * @var int
     */
    private $pid;

    /**
     * worker master pid
     * @var int
     */
    private $masterPid;

    /**
     * @var int
     */
    private $processWorkerId = 0;

    /**
     * @var mixed
     */
    private $user;

    /**
     * @var mixed
     */
    private $group;

    /**
     * @var bool
     */
    private $isReboot = false;

    /**
     * @var bool
     */
    private $isExit = false;

    /**
     * @var bool
     */
    private $isForceExit = false;

    /**
     * @var int
     */
    private $processType = 1;// 1-静态进程，2-动态进程

    /**
     * @var int|float
     */
    private $waitTime = 10;

    /**
     * @var int
     */
    private $readyRebootTime;

    /**
     * @var int
     */
    private $readyExitTime;

    /**
     * @var int
     */
    private $rebootTimerId;

    /**
     * @var int
     */
    private $exitTimerId;

    /**
     * @var int
     */
    private $coroutineId;

    /**
     * @var string
     */
    private $startTime;

    /**
     * @var int
     */
    private $masterLiveTimerId;

    /**
     * 动态进程正在销毁时，原则上在一定时间内不能动态创建进程，常量DYNAMIC_DESTROY_PROCESS_TIME
     * @var bool
     */
    private $isDynamicDestroy = false;

    /**
     * 自动重启次数
     * @var int
     */
    private $rebootCount = 0;

    /**
     * 停止时，存在挂起的协程，进行轮询次数协程是否恢复，并执行完毕，默认5次,子类可以重置
     * @var int
     */
    protected $cycleTimes = 5;

    /**
     * @var int
     *
     */
    protected $initSystemCoroutineNum = 2;

    /**
     * @var bool 业务正在处理中
     * 只对cron的local模式有效
     */
    public $handing = false;

    /**
     * @var int $withBlockLapping = 1,表示每轮任务只能阻塞执行，必须等上一轮任务执行完毕，下一轮才能执行; $withBlockLapping = 0, 表示每轮任务时间到了，都可执行,不管上一轮任务是否已经结束,是并发非租塞的
     * 只对cron的local模式有效,默认=0，可并发执行每轮任务
     */
    protected $withBlockLapping = 0;

    /**
     * @var int 定时任务后台运行，不受stop指令影响，正在执行的任务会继续执行
     * 只对cron的local模式有效
     */
    protected $runInBackground = 1;

    /**
     * @var bool cron接收到退出指令，但因业务还在执行中，只能设置waitToExit=true,等业务处理完了再退出
     */
    protected $waitToExit = false;

    /**
     * static process
     * @var int
     */
    const PROCESS_STATIC_TYPE = 1;

    /**
     * dynamic process
     * @var int
     */
    const PROCESS_DYNAMIC_TYPE = 2;

    /**
     * @var string
     */
    const PROCESS_STATIC_TYPE_NAME = 'static';

    /**
     * @var string
     */
    const PROCESS_DYNAMIC_TYPE_NAME = 'dynamic';

    /**
     * @var string
     */
    const WORKERFY_PROCESS_REBOOT_FLAG = "process::worker::action::reboot";

    /**
     * @var string
     */
    const WORKERFY_PROCESS_EXIT_FLAG = "process::worker::action::exit";

    /**
     * @var string
     */
    const WORKERFY_PROCESS_STATUS_FLAG = "process::worker::action::status";

    /**
     * @var string
     */
    const WORKERFY_ON_EVENT_REBOOT = 'onAfterReboot';

    /**
     * @var string
     */
    const WORKERFY_ON_EVENT_CREATE_DYNAMIC_PROCESS = 'onCreateDynamicProcessCallback';

    /**
     * @var string
     */
    const WORKERFY_ON_EVENT_DESTROY_DYNAMIC_PROCESS = 'onDestroyDynamicProcessCallback';

    /**
     * 动态进程销毁间隔多少秒后，才能再次接受动态创建，防止频繁销毁和创建，最大300s
     * @var int
     */
    const DYNAMIC_DESTROY_PROCESS_TIME = 300;

    /**
     * 定时检查master是否存活的轮询时间
     * @var int
     */
    const CHECK_MASTER_LIVE_TICK_TIME = 30;

    /**
     * AbstractProcess constructor.
     * @param string $process_name
     * @param bool $async
     * @param array $args
     * @param mixed $extend_data
     * @param bool $enable_coroutine
     * @return void
     */
    public function __construct(
        string $process_name,
        bool   $async = true,
        array  $args = [],
               $extend_data = null,
        bool   $enable_coroutine = true
    )
    {
        $this->async           = $async;
        $this->args            = $args;
        $this->extendData      = $extend_data;
        $this->processName     = $process_name;
        $this->enableCoroutine = $enable_coroutine;

        if (isset($args['wait_time']) && is_numeric($args['wait_time'])) {
            $this->waitTime = $args['wait_time'];
        }

        if (isset($args['user']) && is_string($args['user'])) {
            $this->user = $args['user'];
        }

        if (isset($args['group']) && is_string($args['group'])) {
            $this->group = $args['group'];
        }

        if (isset($args['max_process_num'])) {
        }

        if (isset($args['dynamic_destroy_process_time'])) {
        }

        $this->args['check_master_live_tick_time'] = self::CHECK_MASTER_LIVE_TICK_TIME;

        if (isset($args['check_master_live_tick_time'])) {
            if ($args['check_master_live_tick_time'] < self::CHECK_MASTER_LIVE_TICK_TIME) {
                $this->args['check_master_live_tick_time'] = self::CHECK_MASTER_LIVE_TICK_TIME;
            }
        }
        $this->swooleProcess = new \Swoole\Process([$this, '__start'], false, 2, $enable_coroutine);
    }

    /**
     * __start
     *
     * @param Process $swooleProcess
     * @return mixed
     */
    public function __start(Process $swooleProcess)
    {
        try {
            if ($this->isExit) {
                return false;
            }

            $handleClass = static::class;
            putenv("handle_class={$handleClass}");
            BaseServer::reloadGlobalConf();

            static::$processInstance = $this;
            $this->pid = $this->swooleProcess->pid;
            $this->coroutineId = \Swoole\Coroutine::getCid();
            @Process::signal(SIGUSR2, null);
            $this->setUserAndGroup();
            $this->installRegisterShutdownFunction();
            $this->installErrorHandler();
            if ($this->async) {
                Event::add($this->swooleProcess->pipe, function () {
                    try {
                        $message = $this->swooleProcess->read(64 * 1024);
                        if (is_string($message)) {
                            $messageDto = unserialize($message);
                            if (!$messageDto instanceof MessageDto) {
                                $this->fmtWriteError("Accept message type error");
                                return;
                            } else {
                                $msg                 = $messageDto->data;
                                $fromProcessName     = $messageDto->fromProcessName;
                                $fromProcessWorkerId = $messageDto->fromProcessWorkerId;
                                $isProxyByMaster     = $messageDto->isProxy;
                            }
                            if (!isset($isProxyByMaster) || is_null($isProxyByMaster) || $isProxyByMaster === false) {
                                $isProxyByMaster = false;
                            } else {
                                $isProxyByMaster = true;
                            }
                        }
                        if (isset($msg) && isset($fromProcessName) && isset($fromProcessWorkerId)) {
                            $actionHandleFlag = false;
                            if (is_string($msg)) {
                                switch ($msg) {
                                    case self::WORKERFY_PROCESS_REBOOT_FLAG :
                                        $actionHandleFlag = true;
                                        goApp(function () {
                                            if ($this->isStaticProcess()) {
                                                $this->reboot();
                                            } else {
                                                // from cli ctl, dynamic process can not reload. only exit
                                                $this->exit(true, 10);
                                            }
                                        });
                                        break;
                                    case self::WORKERFY_PROCESS_EXIT_FLAG :
                                        $actionHandleFlag = true;
                                        goApp(function () use ($fromProcessName) {
                                            // 定时任务进程业务正在执行中时
                                            if (SystemEnv::isCronService() && $this->runInBackground && $this->handing) {
                                                // 设置进程等待退出，进程将在业务处理完后退出
                                                $this->waitToExit = true;
                                            }else {
                                                if ($fromProcessName == MainManager::MASTER_WORKER_NAME) {
                                                    $this->exit(true,10);
                                                } else {
                                                    $this->exit(false, 30);
                                                }
                                            }
                                        });
                                        break;
                                    case self::WORKERFY_PROCESS_STATUS_FLAG :
                                        $actionHandleFlag = true;
                                        $systemStatus = $this->getProcessSystemStatus();
                                        if (!isset($systemStatus['record_time'])) {
                                            $systemStatus['record_time'] = date('Y-m-d H:i:s');
                                        }
                                        $data = [
                                            'action' => self::WORKERFY_PROCESS_STATUS_FLAG,
                                            'process_name' => $this->getProcessName(),
                                            'data' => [
                                                'worker_id' => $this->getProcessWorkerId(),
                                                'status' => $systemStatus ?? []
                                            ]
                                        ];
                                        $this->writeToMasterProcess($data);
                                        break;
                                }

                            }
                            if ($actionHandleFlag === false) {
                                goApp(function () use ($msg, $fromProcessName, $fromProcessWorkerId, $isProxyByMaster) {
                                    try {
                                        $this->onPipeMsg($msg, $fromProcessName, $fromProcessWorkerId, $isProxyByMaster);
                                    } catch (\Throwable $throwable) {
                                        $this->onHandleException($throwable);
                                    }
                                });
                            }
                        }
                    } catch (\Throwable $throwable) {
                        goApp(function () use ($throwable) {
                            $this->onHandleException($throwable);
                        });
                    }
                });
            }

            // exit signo
            Process::signal(SIGTERM, function ($signo) {
                $function = $this->exitSingleHandle($signo);
                $function();
            });

            // reboot signo
            Process::signal(SIGUSR1, function ($signo) {
                $function = $this->rebootSingleHandle();
                $function();
            });

            $this->initSystemCoroutineNum = $this->getCurrentRunCoroutineNum();

            $this->masterLiveTimerId = \Swoole\Timer::tick(($this->args['check_master_live_tick_time'] + rand(1, 5)) * 1000, function ($timerId) {
                try {
                    $exitFunction = function ($timerId, $masterPid) {
                        \Swoole\Timer::clear($timerId);
                        $processName     = $this->getProcessName();
                        $workerId        = $this->getProcessWorkerId();
                        $this->masterLiveTimerId = null;
                        $this->fmtWriteInfo("Check Parent Master Pid={$masterPid}，children process={$processName},worker_id={$workerId} start to exit");
                        $this->exit(true, 5);
                    };

                    if (!$this->isMasterLive()) {
                        sleep(2);
                        if(!$this->isMasterLive()) {
                            $masterPid  = $this->getMasterPid();
                            // cron防止任务还在进行中,强制退出
                            if (SystemEnv::isCronService()) {
                                if (!$this->handing) {
                                    $exitFunction($timerId, $masterPid);
                                }else {
                                    $this->fmtWriteInfo("Cron Process={$this->getProcessName()} is handing, pid={$this->getPid()}");
                                }
                            }else {
                                $exitFunction($timerId, $masterPid);
                            }
                        }
                    }else {
                        $parentPid = posix_getppid();
                        if($parentPid == 1) {
                            $masterPid = '1(system init)';
                            $this->fmtWriteInfo("This Process of Parent Process is System Init Process, Master Pid={$masterPid}，children process={$this->getProcessName()},worker_id={$this->getProcessWorkerId()} start to exit");
                            // cron防止任务还在进行中,强制退出
                            if (SystemEnv::isCronService()) {
                                if (!$this->handing) {
                                    $exitFunction($timerId, $masterPid);
                                } else {
                                    $this->fmtWriteInfo("Cron Process={$this->getProcessName()} is handing, pid={$this->getPid()}");
                                }
                            }else {
                                $exitFunction($timerId, $masterPid);
                            }
                        }
                    }

                    if ($this->isMasterLive() && $this->getProcessWorkerId() == 0 && $this->masterPid) {
                        $this->saveMasterId($this->masterPid);
                    }

                    // strict reboot exit process
                    if(!empty($this->readyRebootTime) && $this->isRebooting() && time() - $this->readyRebootTime > 60) {
                        \Swoole\Timer::clear($timerId);
                        $this->exit(true, 1);
                    }

                }catch (\Throwable $throwable) {
                    $this->fmtWriteError("Check Master Error Msg={$throwable->getMessage()},trace={$throwable->getTraceAsString()}");
                }
            });

            if (PHP_OS != 'Darwin') {
                $processTypeName = $this->getProcessTypeName();
                if (SystemEnv::isDaemonService()) {
                    $this->swooleProcess->name(APP_NAME."-swoolefy-".WORKER_SERVICE_NAME."-php-daemon[{$processTypeName}-{$this->getPid()}]:" . $this->getProcessName() . '@' . $this->getProcessWorkerId());
                }else if (SystemEnv::isCronService()) {
                    $this->swooleProcess->name(APP_NAME."-swoolefy-".WORKER_SERVICE_NAME."-php-cron[{$processTypeName}-{$this->getPid()}]:" . $this->getProcessName() . '@' . $this->getProcessWorkerId());
                }else {
                    $this->swooleProcess->name(APP_NAME."-swoolefy-".WORKER_SERVICE_NAME."-php-worker[{$processTypeName}-{$this->getPid()}]:" . $this->getProcessName() . '@' . $this->getProcessWorkerId());
                }
            }

            $this->writeStartFormatInfo();

            (new \Swoolefy\Core\EventApp)->registerApp(function () {
                $targetAction = 'init';
                if (method_exists(static::class, $targetAction)) {
                    $this->{$targetAction}();
                }

                // reboot after handle can do send or record msg log or report msg
                $method = self::WORKERFY_ON_EVENT_REBOOT;
                if ($this->getRebootCount() > 0 && method_exists(static::class, $method)) {
                    $this->$method();
                }

                $this->run();
            });
        } catch (\Throwable $throwable) {
            $this->onHandleException($throwable);
        }
    }

    /**
     * @param int $signo
     * @return \Closure
     */
    private function exitSingleHandle(int $signo)
    {
        return function() use($signo) {
            try {
                // destroy
                $isError = false;
                $this->exitAndRebootDefer();
                $this->writeStopFormatInfo();
                $processName = $this->getProcessName();
                $workerId    = $this->getProcessWorkerId();
            } catch (\Throwable $throwable) {
                $processName = isset($processName) ?? '';
                $this->fmtWriteError("Exit error, Process={$processName}, error:" . $throwable->getMessage());
                $isError = true;
            } finally {
                if (!$isError) {
                    $this->fmtWriteInfo("Exit Finish Process={$processName}, worker_id={$workerId}",'green');
                }
                $this->isExit = false;
                Event::del($this->swooleProcess->pipe);
                Event::exit();
                $this->swooleProcess->exit($signo);
            }
        };
    }

    /**
     *
     * defined SIGUSR1 reboot handle
     *
     * @param int $signo
     * @return \Closure
     */
    private function rebootSingleHandle()
    {
        return function () {
            try {
                // destroy
                $this->exitAndRebootDefer();
                $this->writeStopFormatInfo();
                $processName = $this->getProcessName();
                $workerId = $this->getProcessWorkerId();
                $this->fmtWriteInfo("Start to reboot process={$processName}, worker_id={$workerId}");
            } catch (\Throwable $throwable) {
                $this->fmtWriteError("Reboot error, Process={$processName}, error:" . $throwable->getMessage());
            } finally {
                Event::del($this->swooleProcess->pipe);
                Event::exit();
                $this->swooleProcess->exit(SIGUSR1);
                $this->isReboot = false;
            }
        };
    }

    /**
     * exitAndRebootDefer
     */
    private function exitAndRebootDefer()
    {
        // destroy
        if (method_exists(static::class, '__destruct') && version_compare(phpversion(), '8.0.0', '>=') ) {
            $this->__destruct();
        }

        if ($this->masterLiveTimerId) {
            @\Swoole\Timer::clear($this->masterLiveTimerId);
        }
    }

    /**
     * catch out of memory
     *
     * installRegisterShutdownFunction
     */
    protected function installRegisterShutdownFunction()
    {
        register_shutdown_function(function () {
            $error = error_get_last();
            if(null !== $error) {
                $errorStr = sprintf("%s in file %s on line %d",
                    $error['message'],
                    $error['file'],
                    $error['line']
                );
                // out of memory
                if (false !== strpos($error['message'], 'Allowed memory size of')) {
                    $processName = $this->getProcessName().'@'.$this->getProcessWorkerId();
                    $this->fmtWriteError("process name={$processName}, error msg={$errorStr}");
                }

                if(!in_array($error['type'], [E_NOTICE, E_WARNING]) ) {
                    $exception = new WorkerException($errorStr, $error['type']);
                    $this->onHandleException($exception);
                }
            }
        });
    }

    /**
     * writeByProcessName worker send message to process
     * @param string $process_name
     * @param mixed $data
     * @param int $process_worker_id process_worker_id=-1 all process
     * @param bool $is_use_master_proxy
     * @return bool
     */
    public function writeByProcessName(
        string $process_name,
               $data,
        int $process_worker_id = 0,
        bool $is_use_master_proxy = true
    )
    {
        $processManager      = \Swoolefy\Worker\MainManager::getInstance();
        $isMaster            = $processManager->isMaster($process_name);
        $fromProcessName     = $this->getProcessName();
        $fromProcessWorkerId = $this->getProcessWorkerId();

        if ($fromProcessName == $process_name && $process_worker_id == $fromProcessWorkerId) {
            throw new WorkerException('Process can\'t write message to myself');
        }

        if ($isMaster) {
            $toProcessWorkerId               = 0;
            $messageDto                      = new MessageDto();
            $messageDto->fromProcessName     = $fromProcessName;
            $messageDto->fromProcessWorkerId = $fromProcessWorkerId;
            $messageDto->toProcessName       = $processManager->getMasterWorkerName();
            $messageDto->toProcessWorkerId   = $toProcessWorkerId;
            $messageDto->data                = $data;
            $message = serialize($messageDto);
            $this->getSwooleProcess()->write($message);
            return true;
        }

        $processWorkers = [];
        $toTargetProcess = $processManager->getProcessByName($process_name, $process_worker_id);
        if (is_object($toTargetProcess) && $toTargetProcess instanceof AbstractBaseWorker) {
            $processWorkers = [$process_worker_id => $toTargetProcess];
        } else if (is_array($toTargetProcess)) {
            $processWorkers = $toTargetProcess;
        }

        foreach ($processWorkers as $process) {
            if ($process->isRebooting() || $process->isExiting()) {
                $this->fmtWriteInfo("The process={$this->getProcessName()}, worker_id={$this->getProcessWorkerId()} is in isRebooting or isExiting status, not send msg to other process");
                continue;
            }

            $messageDto  = new MessageDto();
            if ($is_use_master_proxy) {
                $messageDto->fromProcessName     = $fromProcessName;
                $messageDto->fromProcessWorkerId = $fromProcessWorkerId;
                $messageDto->toProcessName       = $process->getProcessName();
                $messageDto->toProcessWorkerId   = $process->getProcessWorkerId();
                $messageDto->data                = $data;
                $messageDto->isProxy             = true;
                $message = serialize($messageDto);
                $this->getSwooleProcess()->write($message);
            } else {
                $messageDto->fromProcessName     = $fromProcessName;
                $messageDto->fromProcessWorkerId = $fromProcessWorkerId;
                $messageDto->data                = $data;
                $messageDto->isProxy             = false;
                $message = serialize($messageDto);
                $process->getSwooleProcess()->write($message);
            }
        }
    }

    /**
     * writeToMasterProcess direct send message to other process
     * @param mixed $data
     * @return bool
     */
    public function writeToMasterProcess($data)
    {
        if (empty($data)) {
            return false;
        }
        $processName = MainManager::MASTER_WORKER_NAME;
        $isUseMasterProxy = false;
        $processWorkerId  = 0;
        return $this->writeByProcessName($processName, $data, $processWorkerId, $isUseMasterProxy);
    }

    /**
     * writeToWorkerByMasterProxy, send message to other process by master proxy
     *
     * @param string $process_name
     * @param mixed $data
     * @param int $process_worker_id
     * @return void
     */
    public function writeToWorkerByMasterProxy(string $process_name, $data, int $process_worker_id = 0)
    {
        $isUseMasterProxy = true;
        $this->writeByProcessName($process_name, $data, $process_worker_id, $isUseMasterProxy);
    }

    /**
     * notifyMasterCreateDynamicProcess
     *
     * @param string $dynamic_process_name
     * @param int $dynamic_process_num
     * @return void
     * @throws WorkerException
     */
    public function notifyMasterCreateDynamicProcess(string $dynamic_process_name, int $dynamic_process_num = 2)
    {
        if ($this->isDynamicDestroy) {
            $this->fmtWriteInfo("Process is destroying, forbidden dynamic create process");
            return;
        }

        if(!$this->isStaticProcess()) {
            return;
        }

        $data = [
            'action' => MainManager::CREATE_DYNAMIC_PROCESS_WORKER,
            'process_name' => $dynamic_process_name,
            'data' =>
                [
                    'dynamic_process_num' => $dynamic_process_num
                ]
        ];

        $this->writeToMasterProcess($data);
        $method = self::WORKERFY_ON_EVENT_CREATE_DYNAMIC_PROCESS;
        if(method_exists(static::class, $method)) {
            $this->$method($dynamic_process_name, $dynamic_process_num);
        }
    }

    /**
     * notifyMasterDestroyDynamicProcess
     *
     * @param string $dynamic_process_name
     * @param int $dynamic_process_num
     * @return void
     * @throws \Throwable
     */
    public function notifyMasterDestroyDynamicProcess(string $dynamic_process_name, int $dynamic_process_num = -1)
    {
        if (!$this->isDynamicDestroy) {
            $dynamic_process_num = -1;
            $data = [
                'action' => MainManager::DESTROY_DYNAMIC_PROCESS_WORKER,
                'process_name' => $dynamic_process_name,
                'data' =>
                    [
                        'dynamic_process_num' => $dynamic_process_num
                    ]
            ];
            $this->writeToMasterProcess($data);
            try {
                // 发出销毁指令后，需要在一定时间内避免继续调用动态创建和动态销毁通知这两个函数，因为进程销毁时存在wait_time
                $this->isDynamicDestroy(true);
                $dynamicDestroyProcessTime = $this->waitTime + 10;
                if (isset($this->getArgs()['dynamic_destroy_process_time'])) {
                    $dynamicDestroyProcessTime = $this->getArgs()['dynamic_destroy_process_time'];
                    // max time can not too long
                    if (is_numeric($dynamicDestroyProcessTime)) {
                        if ($dynamicDestroyProcessTime > 300) {
                            $dynamicDestroyProcessTime = self::DYNAMIC_DESTROY_PROCESS_TIME;
                        }
                    }
                }

                $method = self::WORKERFY_ON_EVENT_DESTROY_DYNAMIC_PROCESS;
                if(method_exists(static::class, $method)) {
                    $this->$method($dynamic_process_name, $dynamic_process_num);
                }

                // wait sleep
                \Swoole\Coroutine\System::sleep($dynamicDestroyProcessTime);

            }catch (\Throwable $exception) {
                throw $exception;
            } finally {
                $this->isDynamicDestroy(false);
            }

        }
    }

    /**
     * 通知maser进程重新拉起一个新进程
     *
     * @param string $processName
     * @return void
     */
    private function notifyMasterRebootNewProcess(string $processName)
    {
        $data = [
            'action' => MainManager::REBOOT_PROCESS_WORKER,
            'process_name' => $processName,
            'data' =>
                [
                    'worker_pid' => $this->getPid()
                ]
        ];

        $this->writeToMasterProcess($data);
    }

    /**
     *
     * @param bool $is_destroy
     * @return void
     */
    public function isDynamicDestroy(bool $is_destroy)
    {
        $this->isDynamicDestroy = $is_destroy;
    }

    /**
     * @return bool
     */
    public function isWorker0(): bool
    {
        return $this->getProcessWorkerId() == 0;
    }

    /**
     * start
     * @return mixed
     */
    public function start()
    {
        return $this->swooleProcess->start();
    }

    /**
     * @param array $setting
     * @return void
     */
    public function setCoroutineSetting(array $setting)
    {
        if ($this->enableCoroutine) {
            $setting = array_merge(\Swoole\Coroutine::getOptions() ?? [], $setting);
            !empty($setting) && \Swoole\Coroutine::set($setting);
        }
    }

    /**
     * getProcess
     * @return \Swoole\Process
     */
    public function getProcess()
    {
        return $this->swooleProcess;
    }

    /**
     * @return int
     */
    public function getCoroutineId()
    {
        return $this->coroutineId;
    }

    /**
     * setProcessWorkerId
     * @param int $workerId
     * @return void
     */
    public function setProcessWorkerId(int $workerId)
    {
        $this->processWorkerId = $workerId;
    }

    /**
     * @param int $process_type
     */
    public function setProcessType(int $process_type = self::PROCESS_STATIC_TYPE)
    {
        $this->processType = $process_type;
    }

    /**
     * @return int
     */
    public function getProcessType()
    {
        return $this->processType;
    }

    /**
     * @param int $master_pid
     */
    public function setMasterPid(int $master_pid)
    {
        $this->masterPid = $master_pid;
    }

    /**
     * @return int
     */
    public function getMasterPid(): int
    {
        return $this->masterPid;
    }

    /**
     * @param float $wait_time
     */
    public function setWaitTime(float $wait_time = 30)
    {
        $this->waitTime = $wait_time;
    }

    /**
     * getWaitTime
     * @return int
     */
    public function getWaitTime(): int
    {
        return $this->waitTime;
    }

    /**
     * isRebooting
     * @return bool
     */
    public function isRebooting(): bool
    {
        return $this->isReboot;
    }

    /**
     * isExiting
     * @return bool
     */
    public function isExiting(): bool
    {
        return $this->isExit;
    }

    /**
     * isForceExit
     *
     * @return bool
     */
    public function isForceExit(): bool
    {
        return $this->isForceExit;
    }

    /**
     * loop consumer,满足true才能继续执行业务
     *
     * @return bool
     */
    public function isDue(): bool
    {
        if($this->isRebooting() || $this->isForceExit() || $this->isExiting()) {
            sleep(1);
            $this->fmtWriteInfo("Process Wait to Exit or Reboot");
            return false;
        }
        return true;
    }

    /**
     *
     * @return bool
     */
    public function isStaticProcess(): bool
    {
        if ($this->processType == self::PROCESS_STATIC_TYPE) {
            return true;
        }
        return false;
    }

    /**
     *
     * @return bool
     */
    public function isDynamicProcess(): bool
    {
        return !$this->isStaticProcess();
    }

    /**
     * @return Process
     */
    public function getSwooleProcess(): Process
    {
        return $this->swooleProcess;
    }

    /**
     * getProcessWorkerId
     *
     * @return int
     */
    public function getProcessWorkerId(): int
    {
        return $this->processWorkerId;
    }

    /**
     * getPid
     * @return int
     */
    public function getPid(): int
    {
        return $this->swooleProcess->pid;
    }

    /**
     * @return bool
     */
    public function isStart()
    {
        if (isset($this->pid) && $this->pid > 0) {
            return true;
        }
        return false;
    }

    /**
     * getProcessName
     *
     * @return string
     */
    public function getProcessName()
    {
        return $this->processName;
    }

    /**
     * @return string
     */
    public function getProcessTypeName()
    {
        if ($this->getProcessType() == self::PROCESS_STATIC_TYPE) {
            $processTypeName = self::PROCESS_STATIC_TYPE_NAME;
        } else {
            $processTypeName = self::PROCESS_DYNAMIC_TYPE_NAME;
        }

        return $processTypeName;
    }

    /**
     * getArgs
     * @return mixed
     */
    public function getArgs()
    {
        return $this->args;
    }

    /**
     * @return mixed
     */
    public function getExtendData()
    {
        return $this->extendData;
    }

    /**
     * isAsync
     * @return bool
     */
    public function isAsync()
    {
        return $this->async;
    }

    /**
     *
     * @return bool
     */
    public function isEnableCoroutine()
    {
        return $this->enableCoroutine;
    }

    /**
     * @return mixed
     */
    public function getRebootTimerId()
    {
        return $this->rebootTimerId;
    }

    /**
     * @param int $count
     * @return void
     */
    public function setRebootCount(int $count)
    {
        $this->rebootCount = $count;
    }

    /**
     * @return int
     */
    public function getRebootCount()
    {
        return $this->rebootCount;
    }

    /**
     * @return int
     */
    public function getExitTimerId()
    {
        return $this->exitTimerId;
    }

    /**
     * setStartTime
     *
     * @return void
     */
    public function setStartTime()
    {
        $this->startTime = strtotime('now');
    }

    /**
     * @return int
     */
    public function getStartTime()
    {
        return $this->startTime;
    }

    /**
     * reboot
     *
     * @param float $wait_time
     * @param bool $includeDynamicProcess
     * @return bool
     */
    public function reboot(float $waitTime = 10, bool $includeDynamicProcess = true)
    {
        if(!$includeDynamicProcess) {
            if (!$this->isStaticProcess()) {
                $this->writeReloadFormatInfo();
                return false;
            }
        }

        // rebooting or exiting or force exiting status
        if (!$this->isDue()) {
            return false;
        }

        if ($waitTime < 0) {
            $waitTime = $this->getWaitTime();
        }

        if ($waitTime <= 5) {
            $waitTime = 5;
        }

        $pid = $this->getPid();
        if (Process::kill($pid, 0)) {
            $this->notifyMasterRebootNewProcess($this->getProcessName());
            $this->isReboot = true;
            $this->readyRebootTime = time() + $waitTime;

            $channel = new Channel(1);
            $timerId = \Swoole\Timer::after($waitTime * 1000, function () use ($pid) {
                try {
                    $this->runtimeCoroutineWait($this->cycleTimes);
                    (new \Swoolefy\Core\EventApp)->registerApp(function () {
                        $this->onShutDown();
                    });
                } catch (\Throwable $throwable) {
                    $this->onHandleException($throwable);
                } finally {
                    $this->kill($pid, SIGUSR1);
                }
            });

            $this->rebootTimerId = $timerId;
            // block wait to reboot
            if (\Swoole\Coroutine::getCid() > 0) {
                $channel->pop(-1);
                $channel->close();
            }
        }
        return true;
    }

    /**
     *
     * @param bool $isForce
     * @param float $waitTime
     * @return bool
     */
    public function exit(bool $isForce = false, ?float $waitTime = 10)
    {
        // rebooting or exiting or force exiting status
        if (!$this->isDue()) {
            return false;
        }

        if ($waitTime <= 5) {
            $waitTime = 5;
        }

        $pid = $this->getPid();
        if (Process::kill($pid, 0)) {
            $this->isExit = true;
            if ($isForce) {
                $this->isForceExit = true;
            }

            $this->clearRebootTimer();

            $this->readyExitTime = time() + $waitTime;

            $this->waitToExit = false;

            $channel = new Channel(1);
            $this->exitTimerId = \Swoole\Timer::after($waitTime * 1000, function () use ($pid) {
                try {
                    $this->runtimeCoroutineWait($this->cycleTimes);
                    (new \Swoolefy\Core\EventApp)->registerApp(function () {
                        $this->onShutDown();
                    });
                } catch (\Throwable $throwable) {
                    $this->onHandleException($throwable);
                } finally {
                    if ($this->isForceExit) {
                        $this->kill($pid, SIGKILL);
                    } else {
                        $this->kill($pid, SIGTERM);
                    }
                }
            });

            // block wait to exit
            if (\Swoole\Coroutine::getCid() > 0) {
                $channel->pop(-1);
                $channel->close();
            }
            return true;
        }

    }

    /**
     * registerTickReboot register time reboot, will be called in init() function
     *
     * @param $cron_expression
     * @return void
     */
    protected function registerTickReboot($cron_expression)
    {
        /**
         * local模式下的定时任务模式下不能设置定时重启，否则长时间执行的任务会被kill掉,而是在回调函数注册callback闭包来判断是否达到重启时间
         * @see \Swoolefy\Worker\Cron\CronLocalProcess
         */
        if (SystemEnv::isCronService() && $this instanceof \Swoolefy\Worker\Cron\CronLocalProcess) {
            return;
        }

        if (is_numeric($cron_expression)) {
            $randNum = rand(30, 60);
            // for Example reboot/600s after 600s reboot this process
            if ($cron_expression < 120) {
                $sleepTime = 60;
                $tickTime  = (30 + $randNum) * 1000;
            } else {
                $sleepTime = $cron_expression;
                $tickTime  = (60 + $randNum) * 1000;
            }

            \Swoole\Timer::tick($tickTime, function ($timerId) use ($sleepTime) {
                if (time() - $this->getStartTime() >= $sleepTime) {
                    $this->reboot($this->waitTime);
                    \Swoole\Timer::clear($timerId);
                }
            });
        } else {
            $randSleep   = rand(5, 15);
            $isWorkerId0 = $this->isWorker0();
            // cron expression of timer to reboot this process
            CrontabManager::getInstance()->addRule(
                'system-register-tick-reboot',
                $cron_expression,
                function () use ($randSleep, $isWorkerId0) {
                    if(!$isWorkerId0) {
                        $this->reboot($this->waitTime + $randSleep);
                    }
                    $this->reboot($this->waitTime);
                });
        }

    }

    /**
     * clearRebootTimer
     *
     * @return void
     */
    public function clearRebootTimer()
    {
        if ($this->isReboot) {
            $this->isReboot = false;
            $this->readyRebootTime = null;
        }

        if (isset($this->rebootTimerId) && !empty($this->rebootTimerId)) {
            \Swoole\Timer::clear($this->rebootTimerId);
        }
    }

    /**
     * @param int $pid
     * @param int $signal
     * @return void
     */
    protected function kill($pid, $signal)
    {
        if (Process::kill($pid, 0)) {
            // force exit
            if($signal === SIGKILL) {
                $function = $this->exitSingleHandle($signal);
                $function();
            }else {
                Process::kill($pid, $signal);
            }

        }
    }

    /**
     * isMasterLive
     *
     * @return bool
     */
    public function isMasterLive()
    {
        if ($this->masterPid) {
            if (Process::kill($this->masterPid, 0)) {
                return true;
            } else {
                return false;
            }
        }
    }

    /**
     * worker0 save master_pid to file, because sometime the file will be deleted
     *
     * @param int $master_pid
     * @return void
     */
    protected function saveMasterId(int $master_pid)
    {
        if ($master_pid == $this->masterPid) {
            \Swoole\Coroutine::create(function () use ($master_pid) {
                @file_put_contents(WORKER_PID_FILE, $master_pid);
            });
        }
    }

    /**
     * get coroutine num, wait to sleep second, then do something
     *
     * @return int
     */
    public function getCurrentRunCoroutineNum()
    {
        $coroutineInfo = \Swoole\Coroutine::stats();
        return $coroutineInfo['coroutine_num'];
    }

    /**
     * get current coroutine last cid, compare num achieve value to reboot process
     *
     * @return int
     */
    public function getCurrentCoroutineLastCid()
    {
        $coroutineInfo = \Swoole\Coroutine::stats();
        return $coroutineInfo['coroutine_last_cid'] ?? null;
    }

    /**
     * wait to coroutine
     *
     * @param int $cycle_times
     * @param int $re_wait_time
     * @return void
     */
    private function runtimeCoroutineWait(int $cycle_times = 5, int $re_wait_time = 2)
    {
        if ($cycle_times <= 0) {
            $cycle_times = 2;
        }
        while ($cycle_times > 0) {
            // current run coroutine
            $runCoroutineNum = $this->getCurrentRunCoroutineNum();
            // wait to coroutine to finish of doing something
            if ($runCoroutineNum > ($this->initSystemCoroutineNum ?: 2)) {
                --$cycle_times;
                if (\Swoole\Coroutine::getCid() > 0) {
                    \Swoole\Coroutine\System::sleep($re_wait_time);
                } else {
                    sleep($re_wait_time);
                }
            } else {
                break;
            }
        }
    }

    /**
     * @return AbstractBaseWorker
     */
    public static function getProcessInstance()
    {
        return self::$processInstance;
    }

    /**
     * @return array
     */
    private function getProcessSystemStatus()
    {
        return [
            'memory' => Helper::getMemoryUsage(),
            'coroutine_num' => $this->getCurrentRunCoroutineNum()
        ];
    }

    /**
     * setUserAndGroup Set unix user and group for current process.
     * @return bool
     */
    protected function setUserAndGroup()
    {
        if (!isset($this->user)) {
            return false;
        }
        // Get uid.
        $userInfo = posix_getpwnam($this->user);
        if (!$userInfo) {
            $this->fmtWriteError("User {$this->user} not exist");
            $this->exit();
            return false;
        }
        $uid = $userInfo['uid'];
        // Get gid.
        if ($this->group) {
            $groupInfo = posix_getgrnam($this->group);
            if (!$groupInfo) {
                $this->fmtWriteError("Group {$this->group} not exist");
                $this->exit();
                return false;
            }
            $gid = $groupInfo['gid'];
        } else {
            $gid = $userInfo['gid'];
            $this->group = $gid;
        }
        // Set uid and gid.
        if ($uid !== posix_getuid() || $gid !== posix_getgid()) {
            if (!posix_setgid($gid) || !posix_initgroups($userInfo['name'], $gid) || !posix_setuid($uid)) {
                $this->fmtWriteInfo("change gid or uid failed");
            }
        }
    }

    /**
     * @return array
     */
    public function getUserAndGroup()
    {
        return [$this->user, $this->group];
    }

    /**
     * writeStartFormatInfo
     * @return void
     */
    private function writeStartFormatInfo()
    {
        $processName = $this->getProcessName();
        $workerId = $this->getProcessWorkerId();
        if ($this->getProcessType() == self::PROCESS_STATIC_TYPE) {
            if ($this->getRebootCount() > 0) {
                $processTypeName = 'static-reboot';
            } else {
                $processTypeName = self::PROCESS_STATIC_TYPE_NAME;
            }
        } else {
            $processTypeName = self::PROCESS_DYNAMIC_TYPE_NAME;
        }
        $pid = $this->getPid();
        $logInfo = "--start children_process【{$processTypeName}】: {$processName}@{$workerId} started, pid={$pid}, master_pid={$this->getMasterPid()}";
        $this->fmtWriteInfo($logInfo);
    }

    /**
     * writeStopFormatInfo
     * @return void
     */
    private function writeStopFormatInfo()
    {
        $processName = $this->getProcessName();
        $workerId = $this->getProcessWorkerId();
        $pid = $this->getPid();
        $logInfo = "【 Ready To Stop 】process_name={$processName},worker_id={$workerId}, pid={$pid}, master_pid={$this->getMasterPid()}";
        $this->fmtWriteInfo($logInfo);
    }

    /**
     * writeReloadFormatInfo
     *
     * @return void
     */
    private function writeReloadFormatInfo()
    {
        if ($this->getProcessType() == self::PROCESS_DYNAMIC_TYPE) {
            $processName = $this->getProcessName();
            $workerId    = $this->getProcessWorkerId();
            $processType = self::PROCESS_DYNAMIC_TYPE_NAME;
            $pid         = $this->getPid();
            $logInfo     = "--start children_process【{$processType}】: {$processName}@{$workerId} start(默认动态创建的进程不支持reload，可以使用 kill -10 pid 强制重启), Pid={$pid}";
            $this->fmtWriteInfo($logInfo);
        }
    }

    /**
     * after start to run of process
     *
     * @return void
     */
    public abstract function run();

    /**
     * 处理自定义命令函数，终端向某个进程发送指令，eg:
     * php cron.php send Test --name=test-local-cron-worker --action=run-once-cron
     *
     * @param mixed $msg
     * @param string $from_process_name
     * @param int $from_process_worker_id
     * @param bool $is_proxy_by_master
     * @return mixed
     */
    public function onPipeMsg($msg, string $from_process_name, int $from_process_worker_id, bool $is_proxy_by_master)
    {
        if (is_string($msg)) {
            $msg = json_decode($msg, true) ?? $msg;
            if (is_array($msg) && isset($msg['action'])) {
                $commandHandleList = array_merge($this->systemCommandHandle, $this->customCommandHandle);
                $commandHandleItem = $commandHandleList[$msg['action']] ?? [];
                if (!empty($commandHandleItem) && is_array($commandHandleItem) && count($commandHandleItem) == 2) {
                    list($class, $method) = $commandHandleItem;
                    if ($class == self::class || is_subclass_of($class, self::class)) {
                        if (class_exists(static::class, $method)) {
                            $this->{$method}($msg, $from_process_name, $from_process_worker_id, $is_proxy_by_master);
                        }
                    }else {
                        (new $class)->{$method}($msg, $from_process_name, $from_process_worker_id, $is_proxy_by_master);
                    }
                }else {
                    $this->fmtWriteError(sprintf("onPipeMsg accept msg=%s is not match property of customCommandHandle key, please check it", json_encode($msg, JSON_UNESCAPED_UNICODE)));
                }
            }
        }
    }

    /**
     * onShutDown
     * @return void
     */
    public function onShutDown()
    {
    }

    /**
     * onHandleException
     * @param \Throwable $throwable
     * @param array $context
     * @return void
     */
    protected function onHandleException(\Throwable $throwable, array $context = [])
    {
        \Swoolefy\Core\BaseServer::catchException($throwable);
    }

}