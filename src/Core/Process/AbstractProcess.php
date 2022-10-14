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

namespace Swoolefy\Core\Process;

use Swoole\Process;
use Swoolefy\Core\Swfy;
use Swoolefy\Core\BaseServer;
use Swoole\Coroutine\Channel;
use Swoolefy\Core\EventController;
use Swoolefy\Core\Table\TableManager;
use Swoolefy\Exception\SystemException;

abstract class AbstractProcess
{

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
     * @var mixed|null
     */
    private $extendData = null;

    /**
     * @var bool
     */
    private $enableCoroutine = true;

    /**
     * @var bool
     */
    private $isExiting = false;

    /**
     * kill reboot flag
     */
    const SWOOLEFY_PROCESS_KILL_FLAG = "action::restart::action::reboot";

    /**
     * AbstractProcess constructor.
     * @param string $processName
     * @param bool $async
     * @param array $args
     * @param null $extend_data
     * @param bool $enable_coroutine
     * @return void
     */
    public function __construct(
        string $processName,
        bool   $async = true,
        array  $args = [],
               $extend_data = null,
        bool   $enable_coroutine = true
    )
    {
        $this->async = $async;
        $this->args = $args;
        $this->extendData = $extend_data;
        $this->processName = $processName;
        if($this->isWorkerService()) {
            $this->enableCoroutine = $enable_coroutine;
        }else {
            $this->enableCoroutine = true;
        }
        $this->swooleProcess = new \Swoole\Process([$this, '__start'], false, SOCK_DGRAM, $this->enableCoroutine);
        Swfy::getServer()->addProcess($this->swooleProcess);
    }

    /**
     * __start
     * @param Process $process
     * @return void
     */
    public function __start(Process $process)
    {
        $this->setWorkerMasterPid();
        if (method_exists(static::class, 'beforeStart')) {
            $this->beforeStart();
        }
        $this->installRegisterShutdownFunction();
        TableManager::getTable('table_process_map')->set(
            md5($this->processName), ['pid' => $this->swooleProcess->pid]
        );

        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
        }

        Process::signal(SIGTERM, function () use ($process) {
            // destroy
            if (method_exists(static::class, '__destruct') && version_compare(phpversion(), '8.0.0', '>=') ) {
                $this->__destruct();
            }
            TableManager::getTable('table_process_map')->del(md5($this->processName));
            \Swoole\Event::del($process->pipe);
            \Swoole\Event::exit();
            $this->swooleProcess->exit(0);
        });

        if ($this->async) {
            \Swoole\Event::add($this->swooleProcess->pipe, function () {
                $msg = $this->swooleProcess->read(64 * 1024);
                \Swoole\Coroutine::create(function () use ($msg) {
                    try {
                        if ($msg == static::SWOOLEFY_PROCESS_KILL_FLAG) {
                            $this->reboot();
                            return;
                        } else {
                            $message = json_decode($msg, true) ?? $msg;
                            if(!$this->isWorkerService() || $this->enableCoroutine) {
                                (new \Swoolefy\Core\EventApp)->registerApp(function (EventController $eventApp) use ($message) {
                                    $this->onReceive($message);
                                });
                            }else {
                                $this->onReceive($message);
                            }
                        }
                    } catch (\Throwable $throwable) {
                        $this->onHandleException($throwable);
                    }
                });
            });
        }

        if(!$this->isWorkerService() || $this->enableCoroutine) {
            \Swoole\Timer::tick((10+rand(1,10)) * 1000, function ($timerId) {
                $swooleMasterPid = Swfy::getMasterPid();
                if(!\Swoole\Process::kill($swooleMasterPid, 0)) {
                    sleep(1);
                    if(!\Swoole\Process::kill($swooleMasterPid, 0)) {
                        \Swoole\Timer::clear($timerId);
                        \Swoole\Process::kill($swooleMasterPid, SIGTERM);
                    }
                }else {
                    $parentPid = posix_getppid();
                    if($parentPid == 1) {
                        \Swoole\Timer::clear($timerId);
                        \Swoole\Process::kill($swooleMasterPid, SIGTERM);
                    }
                }
            });
        }

        $this->swooleProcess->name(BaseServer::getAppPrefix() . ':' . 'php-swoolefy-user-process:' . $this->getProcessName());

        try {
            if(!$this->isWorkerService() || $this->enableCoroutine) {
                (new \Swoolefy\Core\EventApp)->registerApp(function (EventController $eventApp) {
                    $this->init();
                    $this->run();
                });
            }else {
                $this->init();
                $this->run();
            }
        } catch (\Throwable $throwable) {
            $this->onHandleException($throwable);
        }

    }

    /**
     * getArgs 获取变量参数
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
     * getProcess
     * @return Process
     */
    public function getProcess()
    {
        return $this->swooleProcess;
    }

    /**
     * 服务启动后才能获得到创建的进程pid,不启动为null
     *
     * @return int|null
     */
    public function getPid()
    {
        $pid = TableManager::getTable('table_process_map')->get(md5($this->processName), 'pid');
        if ($pid) {
            return $pid;
        }else {
            return posix_getpid();
        }
    }

    /**
     * @return bool
     */
    protected function isWorkerService()
    {
        if(!defined('IS_WORKER_SERVICE') || empty(IS_WORKER_SERVICE)) {
            return false;
        }

        return true;
    }

    /**
     * @return string
     */
    public function getSwoolefyProcessKillFlag()
    {
        return static::SWOOLEFY_PROCESS_KILL_FLAG;
    }

    /**
     * getProcessName
     * @return string
     */
    public function getProcessName()
    {
        return $this->processName;
    }

    /**
     * isEnableCoroutine
     * @return bool
     */
    public function isEnableCoroutine()
    {
        return $this->enableCoroutine;
    }

    /**
     * sendMessage 向worker进程发送数据(包含task进程)，worker进程将通过onPipeMessage函数监听获取数数据，默认向worker0发送
     * @param mixed $msg
     * @param int $worker_id
     * @return bool
     * @throws \Exception
     */
    public function sendMessage($msg = null, int $worker_id = 0)
    {
        if ($worker_id >= 1) {
            $workerTaskTotalNum = (int)Swfy::getServer()->setting['worker_num'] + (int)Swfy::getServer()->setting['task_worker_num'];
            if ($worker_id >= $workerTaskTotalNum) {
                throw new SystemException("Param of worker_id must <=$workerTaskTotalNum");
            }
        }

        if (!$msg) {
            throw new SystemException('Param of msg can not be null or empty');
        }

        return Swfy::getServer()->sendMessage($msg, $worker_id);
    }

    /**
     * @return void
     */
    protected function setWorkerMasterPid()
    {
        defined('WORKER_MASTER_PID') or define('WORKER_MASTER_PID', $this->getPid());
        if(defined('WORKER_PID_FILE')) {
            file_put_contents(WORKER_PID_FILE, $this->getPid());
        }
    }

    /**
     * reboot
     * @return void
     */
    public function reboot()
    {
        if (!$this->isExiting) {
            $this->isExiting = true;
            $channel = new Channel(1);
            \Swoole\Coroutine::create(function () {
                try {
                    $this->runtimeCoroutineWait();
                    (new \Swoolefy\Core\EventApp)->registerApp(function (EventController $event) {
                        $this->onShutDown();
                    });
                } catch (\Throwable $throwable) {
                    $this->onHandleException($throwable);
                } finally {
                    \Swoole\Process::kill($this->getPid(), SIGTERM);
                }
            });

            if (\Swoole\Coroutine::getCid() > 0) {
                $channel->pop(-1);
                $channel->close();
            }
        }
    }

    /**
     * @return bool
     */
    public function isExiting()
    {
        return $this->isExiting;
    }

    /**
     * getCurrentRunCoroutineNum 获取当前进程中正在运行的协程数量，可以通过这个值判断比较，防止协程过多创建，可以设置sleep等待
     * @return int
     */
    public function getCurrentRunCoroutineNum()
    {
        $coroutine_info = \Swoole\Coroutine::stats();
        return $coroutine_info['coroutine_num'] ?? null;
    }

    /**
     * getCurrentCoroutineLastCid 获取当前进程的协程cid已分配到哪个值，可以根据这个值设置进程reboot,防止cid超出最大数
     * @return int|null
     */
    public function getCurrentCoroutineLastCid()
    {
        $coroutineInfo = \Swoole\Coroutine::stats();
        return $coroutineInfo['coroutine_last_cid'] ?? null;
    }

    /**
     * 对于运行态的协程，还没有执行完的，设置一个再等待时间$re_wait_time
     * @param int $cycle_times 轮询次数
     * @param int $re_wait_time 每次2s轮询
     * @return void
     */
    private function runtimeCoroutineWait(int $cycle_times = 5, int $re_wait_time = 2)
    {
        if ($cycle_times <= 0) {
            $cycle_times = 2;
        }
        while ($cycle_times > 0) {
            $runCoroutineNum = $this->getCurrentRunCoroutineNum();
            // 除了主协程和runtimeCoroutineWait跑在协程中，所以等于2个协程，还有其他协程没唤醒，则再等待
            if ($runCoroutineNum > 2) {
                --$cycle_times;
                \Swoole\Coroutine::sleep($re_wait_time);
            } else {
                break;
            }
        }
    }

    /**
     * catch out of memory
     *
     * installRegisterShutdownFunction
     * @return void
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
                if(!in_array($error['type'], [E_NOTICE, E_WARNING]) ) {
                    $exception = new SystemException($errorStr, $error['type']);
                    $this->onHandleException($exception);
                }
            }
        });
    }

    /**
     * init
     * @return void
     */
    public function init()
    {
    }

    /**
     * run
     * @return void
     */
    abstract public function run();

    /**
     * @return mixed
     */
    public function onShutDown()
    {
    }

    /**
     * @param mixed $msg
     * @param mixed ...$args
     * @return mixed
     */
    public function onReceive($msg, ...$args)
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
        BaseServer::catchException($throwable);
    }

}