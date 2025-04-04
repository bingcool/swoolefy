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
use Swoolefy\Core\SystemEnv;
use Swoolefy\Core\Table\TableManager;
use Swoolefy\Exception\SystemException;

abstract class AbstractProcess
{
    /**
     * @var Process
     */
    private $swooleProcess;

    /**
     * @var AbstractProcess
     */
    protected static $processInstance;

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
     * @param mixed $extendData
     * @param bool $enableCoroutine
     * @return void
     */
    public function __construct(
        string $processName,
        bool   $async = true,
        array  $args = [],
               $extendData = null,
        bool   $enableCoroutine = true
    )
    {
        $this->async = $async;
        $this->args  = $args;
        $this->extendData  = $extendData;
        $this->processName = $processName;
        if($this->isWorkerService()) {
            $this->enableCoroutine = $enableCoroutine;
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
        $handleClass = static::class;
        putenv("handle_class={$handleClass}");
        BaseServer::reloadGlobalConf();

        $this->setWorkerMasterPid();
        if (method_exists(static::class, 'beforeStart')) {
            $this->beforeStart();
        }

        // fork 进程会复制swoole master 进程的server socket 资源,然后fork出来的子进程退出时，还可能持续几十秒处理完业务才退出.而此时swoole再启动时，端口被还没退出的子进程占用的，导致重启时，可能会显示端口占用
        // 这里子进程直接关闭继承(从父进程复制的)socket的fd,只影响当前子进程，那么当前子进程将不会占用port, 不影响swoole master进程监听.也就是父子进程socket资源的复制，关闭socket不相互影响
        if (SystemEnv::isWorkerService()) {
            // 非协程环境才可以
            if (\Swoole\Coroutine::getCid() <= 0 && is_object(BaseServer::getServer())) {
                $socket = BaseServer::getServer()->getSocket();
                socket_close($socket);
            }
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

            // script 模式下.任务进程退出时，父进程也得退出
            if (SystemEnv::isScriptService()) {
                $swooleMasterPid = Swfy::getMasterPid();
                if (\Swoole\Process::kill($swooleMasterPid, 0)) {
                    \Swoole\Process::kill($swooleMasterPid, SIGTERM);
                }
                if (file_exists(WORKER_PID_FILE)) {
                    @unlink(WORKER_PID_FILE);
                }
            }

            $this->swooleProcess->exit(0);
        });

        if ($this->async) {
            \Swoole\Event::add($this->swooleProcess->pipe, function () {
                $msg = $this->swooleProcess->read(64 * 1024);
                goApp(function () use ($msg) {
                    try {
                        if ($msg == static::SWOOLEFY_PROCESS_KILL_FLAG) {
                            $this->reboot();
                            return;
                        } else {
                            $message = json_decode($msg, true) ?? $msg;
                            if(!$this->isWorkerService() || $this->enableCoroutine) {
                                $this->onReceive($message);
                            }else {
                                goApp(function () use($message) {
                                    $this->onReceive($message);
                                });
                            }
                        }
                    } catch (\Throwable $throwable) {
                        $this->onHandleException($throwable);
                    }
                });
            });
        }

        if(!$this->isWorkerService() || $this->enableCoroutine) {
            \Swoole\Timer::tick((10 + rand(1,10)) * 1000, function ($timerId) {
                $swooleMasterPid = Swfy::getMasterPid();
                if(!\Swoole\Process::kill($swooleMasterPid, 0)) {
                    sleep(1);
                    if(!\Swoole\Process::kill($swooleMasterPid, 0)) {
                        \Swoole\Timer::clear($timerId);
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

        $this->setProcessName();
        static::$processInstance = $this;

        try {
            (new \Swoolefy\Core\EventApp)->registerApp(function () {
                $this->init();
                $this->run();
            });
        } catch (\Throwable $throwable) {
            $this->onHandleException($throwable);
        }

    }

    /**
     * setProcessName
     *
     * @return void
     */
    protected function setProcessName()
    {
        if (SystemEnv::isWorkerService()) {
            if (SystemEnv::isScriptService()) {
                $this->swooleProcess->name(BaseServer::getAppPrefix() . ':' . '-swoolefy-worker-script-php:' . getenv('c'));
            }else if (SystemEnv::isDaemonService()) {
                $this->swooleProcess->name(BaseServer::getAppPrefix() . ':' . 'swoolefy-worker-daemon-php:' . $this->getProcessName());
            }else if (SystemEnv::isCronService()) {
                $this->swooleProcess->name(BaseServer::getAppPrefix() . ':' . 'swoolefy-worker-cron-php:' . $this->getProcessName());
            }else {
                $this->swooleProcess->name(BaseServer::getAppPrefix() . ':' . 'php-swoolefy-user-process:' . $this->getProcessName());
            }
        }else {
            $this->swooleProcess->name(BaseServer::getAppPrefix() . ':' . 'php-swoolefy-user-process:' . $this->getProcessName());
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
        return SystemEnv::isWorkerService();
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
     * @throws SystemException
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
            goApp(function () {
                try {
                    $this->runtimeCoroutineWait();
                    $this->onShutDown();
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
     * @return AbstractProcess
     */
    public static function getProcessInstance(): AbstractProcess
    {
        return self::$processInstance;
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
     * @param float $re_wait_time 每次2s轮询等待
     * @return void
     */
    private function runtimeCoroutineWait(int $cycle_times = 5, float $re_wait_time = 2.0 )
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
     * @param string $name
     * @return bool
     */
    public function getOption(string $name)
    {
        return getenv($name);
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