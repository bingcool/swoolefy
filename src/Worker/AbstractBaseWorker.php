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
use Swoolefy\Core\Crontab\CrontabManager;
use Swoolefy\Core\EventController;
use Swoolefy\Worker\Dto\MessageDto;

/**
 * Class AbstractProcess
 * @package Workerfy
 */
abstract class AbstractBaseWorker
{

    use Traits\SystemTrait;

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
     * @var bool|null
     */
    private $async = null;

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
     * @param null $extend_data
     * @param bool $enable_coroutine
     * @return void
     */
    public function __construct(
        string $process_name,
        bool $async = true,
        array $args = [],
               $extend_data = null,
        bool $enable_coroutine = true
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
                                $this->writeInfo("【Error】Accept message type error");
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
                                        \Swoole\Coroutine::create(function () {
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
                                        \Swoole\Coroutine::create(function () use ($fromProcessName) {
                                            if ($fromProcessName == MainManager::MASTER_WORKER_NAME) {
                                                $this->exit(true,10);
                                            } else {
                                                $this->exit(false, 30);
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
                                \Swoole\Coroutine::create(function () use ($msg, $fromProcessName, $fromProcessWorkerId, $isProxyByMaster) {
                                    try {
                                        (new \Swoolefy\Core\EventApp)->registerApp(function (EventController $eventApp) use($msg, $fromProcessName, $fromProcessWorkerId, $isProxyByMaster) {
                                            $this->onPipeMsg($msg, $fromProcessName, $fromProcessWorkerId, $isProxyByMaster);
                                        });

                                    } catch (\Throwable $throwable) {
                                        $this->onHandleException($throwable);
                                    }
                                });
                            }
                        }
                    } catch (\Throwable $throwable) {
                        \Swoole\Coroutine::create(function () use ($throwable) {
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
                        $this->writeInfo("【Warning】Check Parent Master Pid={$masterPid}，children process={$processName},worker_id={$workerId} start to exit");
                        $this->exit(true, 5);
                    };

                    if (!$this->isMasterLive()) {
                        sleep(2);
                        if(!$this->isMasterLive()) {
                            $masterPid  = $this->getMasterPid();
                            $exitFunction($timerId, $masterPid);
                        }
                    }else {
                        $parentPid = posix_getppid();
                        if($parentPid == 1) {
                            $masterPid = '1(system init)';
                            $exitFunction($timerId, $masterPid);
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
                    $this->writeInfo("【Error】Check Master Error Msg={$throwable->getMessage()},trace={$throwable->getTraceAsString()}");
                }
            });

            if (PHP_OS != 'Darwin') {
                $processTypeName = $this->getProcessTypeName();
                $this->swooleProcess->name(APP_NAME."-swoolefy-".WORKER_SERVICE_NAME."-php-worker[{$processTypeName}-{$this->getPid()}]:" . $this->getProcessName() . '@' . $this->getProcessWorkerId());
            }

            $this->writeStartFormatInfo();

            (new \Swoolefy\Core\EventApp)->registerApp(function (EventController $eventApp) {
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
        return function() use($signo){
            try {
                // destroy
                $this->exitAndRebootDefer();
                $this->writeStopFormatInfo();
                $processName = $this->getProcessName();
                $workerId    = $this->getProcessWorkerId();
                $this->writeInfo("【Info】 Start to exit process={$processName}, worker_id={$workerId}");
            } catch (\Throwable $throwable) {
                $this->writeInfo("【Error】Exit error, Process={$processName}, error:" . $throwable->getMessage());
            } finally {
                Event::del($this->swooleProcess->pipe);
                Event::exit();
                $this->swooleProcess->exit($signo);
                $this->isExit = false;
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
                $this->writeInfo("【Info】Start to reboot process={$processName}, worker_id={$workerId}");
            } catch (\Throwable $throwable) {
                $this->writeInfo("【Error】Reboot error, Process={$processName}, error:" . $throwable->getMessage());
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
                    $this->writeInfo("【Error】{$errorStr}, process_name={$processName}");
                }

                if(!in_array($error['type'], [E_NOTICE, E_WARNING]) ) {
                    $exception = new \Exception($errorStr, $error['type']);
                    $this->onHandleException($exception);
                }
            }
        });
    }

    /**
     * writeByProcessName worker send message to process
     * @param string $process_name
     * @param $data
     * @param int $process_worker_id process_worker_id=-1 all process
     * @param bool $is_use_master_proxy
     * @return bool
     * @throws Exception
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
            throw new \Exception('Process can\'t write message to myself');
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
                $this->writeInfo("【Warning】the process(worker_id={$this->getProcessWorkerId()}) is in isRebooting or isExiting status, not send msg to other process");
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
     * writeToMasterProcess direct semd message to other process
     * @param mixed $data
     * @return bool
     * @throws Exception
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
     * @throws Exception
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
     * @throws \Exception
     */
    public function notifyMasterCreateDynamicProcess(string $dynamic_process_name, int $dynamic_process_num = 2)
    {
        if ($this->isDynamicDestroy) {
            $this->writeInfo("【Warning】process is destroying, forbidden dynamic create process");
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
                if(class_exists('Swoole\Coroutine\System'))
                {
                    \Swoole\Coroutine\System::sleep($dynamicDestroyProcessTime);
                }else
                {
                    \Swoole\Coroutine::sleep($dynamicDestroyProcessTime);
                }

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
    public function isWorker0()
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
     * @param int $id
     * @return void
     */
    public function setProcessWorkerId(int $id)
    {
        $this->processWorkerId = $id;
    }

    /**
     * @param int $process_type
     */
    public function setProcessType(int $process_type = 1)
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
    public function getMasterPid()
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
    public function getWaitTime()
    {
        return $this->waitTime;
    }

    /**
     * isRebooting
     * @return bool
     */
    public function isRebooting()
    {
        return $this->isReboot;
    }

    /**
     * isExiting
     * @return bool
     */
    public function isExiting()
    {
        return $this->isExit;
    }

    /**
     * isForceExit
     *
     * @return bool
     */
    public function isForceExit()
    {
        return $this->isForceExit;
    }

    /**
     * loop consumer
     *
     * @return bool
     */
    public function isDue()
    {
        if($this->isRebooting() || $this->isForceExit() || $this->isExiting()) {
            sleep(1);
            $this->writeInfo("【INFO】Process Wait to Exit or Reboot");
            return false;
        }
        return true;
    }

    /**
     *
     * @return bool
     */
    public function isStaticProcess()
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
    public function isDynamicProcess()
    {
        return !$this->isStaticProcess();
    }

    /**
     * @return Process
     */
    public function getSwooleProcess()
    {
        return $this->swooleProcess;
    }

    /**
     * getProcessWorkerId
     *
     * @return int
     */
    public function getProcessWorkerId()
    {
        return $this->processWorkerId;
    }

    /**
     * getPid
     * @return int
     */
    public function getPid()
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
    public function reboot(float $waitTime = 10, bool $includeDynamicProcess = false)
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
                    (new \Swoolefy\Core\EventApp)->registerApp(function (EventController $eventApp) {
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
     * @param bool $is_force
     * @param float $wait_time
     * @return bool
     */
    public function exit(bool $is_force = false, ?float $wait_time = 10)
    {
        // rebooting or exiting or force exiting status
        if (!$this->isDue()) {
            return false;
        }

        if ($wait_time <= 5) {
            $waitTime = 5;
        }else {
            $waitTime = $wait_time;
        }

        $pid = $this->getPid();
        if (Process::kill($pid, 0)) {
            $this->isExit = true;
            if ($is_force) {
                $this->isForceExit = true;
            }

            $this->clearRebootTimer();

            $this->readyExitTime = time() + $waitTime;

            $channel = new Channel(1);
            $this->exitTimerId = \Swoole\Timer::after($waitTime * 1000, function () use ($pid) {
                try {
                    $this->runtimeCoroutineWait($this->cycleTimes);
                    (new \Swoolefy\Core\EventApp)->registerApp(function (EventController $eventApp) {
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
                        sleep($randSleep);
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
            $this->writeInfo("【Warning】User {$this->user} not exist");
            $this->exit();
            return false;
        }
        $uid = $userInfo['uid'];
        // Get gid.
        if ($this->group) {
            $groupInfo = posix_getgrnam($this->group);
            if (!$groupInfo) {
                $this->writeInfo("【Warning】Group {$this->group} not exist");
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
                $this->writeInfo("【Warning】change gid or uid failed");
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
                $processType = 'static-reboot';
            } else {
                $processType = self::PROCESS_STATIC_TYPE_NAME;
            }
        } else {
            $processType = self::PROCESS_DYNAMIC_TYPE_NAME;
        }
        $pid = $this->getPid();
        $logInfo = "start children_process【{$processType}】: {$processName}@{$workerId} started, pid={$pid}, master_pid={$this->getMasterPid()}";
        $this->writeInfo($logInfo, 'green');
    }

    /**
     * writeStopFormatInfo
     * @return void
     */
    private function writeStopFormatInfo()
    {
        $processName = $this->getProcessName();
        $workerId = $this->getProcessWorkerId();
        if ($this->getProcessType() == self::PROCESS_STATIC_TYPE) {
            $processType = self::PROCESS_STATIC_TYPE_NAME;
        } else {
            $processType = self::PROCESS_DYNAMIC_TYPE_NAME;
        }
        $pid = $this->getPid();
        $logInfo = "stop children_process【{$processType}】: {$processName}@{$workerId} stopped, pid={$pid}, master_pid={$this->getMasterPid()}";
        $this->writeInfo($logInfo, 'red');
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
            $logInfo     = "start children_process【{$processType}】: {$processName}@{$workerId} start(默认动态创建的进程不支持reload，可以使用 kill -10 pid 强制重启), Pid={$pid}";
            $this->writeInfo($logInfo, 'red');
        }
    }

    /**
     * after start to run of process
     *
     * @return void
     */
    public abstract function run();

    /**
     * @param mixed $msg
     * @param string $from_process_name
     * @param int $from_process_worker_id
     * @param bool $is_proxy_by_master
     * @return mixed
     */
    public function onPipeMsg($msg, string $from_process_name, int $from_process_worker_id, bool $is_proxy_by_master)
    {
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
    public function onHandleException(\Throwable $throwable, array $context = [])
    {
        //todo
    }

}