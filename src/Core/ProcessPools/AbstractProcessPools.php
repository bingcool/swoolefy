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

namespace Swoolefy\Core\ProcessPools;

use Swoole\Process;
use Swoolefy\Core\Swfy;
use Swoolefy\Core\BaseServer;
use Swoole\Coroutine\Channel;
use Swoolefy\Core\Table\TableManager;
use Swoolefy\Exception\SystemException;

abstract class AbstractProcessPools
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
    private $extendData;

    /**
     * @var int
     */
    private $bindWorkerId = null;

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
     * AbstractProcessPools constructor.
     * @param string $process_name
     * @param bool $async
     * @param array $args
     * @param mixed $extend_data
     * @param bool $enableCoroutine
     * @return void
     */
    public function __construct(
        string $process_name,
        bool   $async = true,
        array  $args = [],
        mixed  $extend_data = null,
        bool   $enableCoroutine = true
    )
    {
        $this->async = $async;
        $this->args  = $args;
        $this->extendData  = $extend_data;
        $this->processName = $process_name;
        $this->enableCoroutine = true;
        $this->swooleProcess   = new \Swoole\Process([$this, '__start'], false, SOCK_DGRAM, $this->enableCoroutine);
        Swfy::getServer()->addProcess($this->swooleProcess);
    }

    /**
     * getProcess 获取process进程对象
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
        $pid = TableManager::getTable('table_process_pools_map')->get(md5($this->processName), 'pid');
        if ($pid) {
            return $pid;
        }
        return null;
    }

    /**
     * @return string
     */
    public function getSwoolefyProcessKillFlag()
    {
        return static::SWOOLEFY_PROCESS_KILL_FLAG;
    }

    /**
     * setBindWorkerId 进程绑定对应的worker
     * @param int $worker_id
     * @return void
     */
    public function setBindWorkerId(int $worker_id)
    {
        $this->bindWorkerId = $worker_id;
    }

    /**
     * getBindWorkerId 获取绑定的worker_id
     * @return int
     */
    public function getBindWorkerId()
    {
        return $this->bindWorkerId;
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

        TableManager::getTable('table_process_pools_map')->set(
            md5($this->processName), ['pid' => $this->swooleProcess->pid, 'process_name' => $this->processName]
        );

        BaseServer::reloadGlobalConf();

        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
        }

        Process::signal(SIGTERM, function () use ($process) {
            // destroy
            if (method_exists(static::class, '__destruct') && version_compare(phpversion(), '8.0.0', '>=') ) {
                $this->__destruct();
            }
            TableManager::getTable('table_process_pools_map')->del(md5($this->processName));
            \Swoole\Event::del($process->pipe);
            \Swoole\Event::exit();
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
                            $this->onReceive($message);
                        }
                    } catch (\Throwable $throwable) {
                        BaseServer::catchException($throwable);
                    }
                });
            });
        }

        $this->swooleProcess->name(BaseServer::getAppPrefix() . ':' . 'php-swoolefy-user-process-pools' . $this->bindWorkerId . ':' . $this->getProcessName(true));
        try {
            (new \Swoolefy\Core\EventApp)->registerApp(function () {
                $this->init();
                $this->run();
            });
        } catch (\Throwable $t) {
            BaseServer::catchException($t);
        }
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
     * @return mixed|null
     */
    public function getExtendData()
    {
        return $this->extendData;
    }

    /**
     * getProcessName
     * @param bool $is_full_name
     * @return string
     */
    public function getProcessName(bool $is_full_name = false)
    {
        if (!$is_full_name) {
            list($processName, $workerId, $processNum) = explode('@', $this->processName);
            return $processName;
        }
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
     * sendMessage 向绑定的worker进程发送数据
     * worker进程将通过onPipeMessage函数异步监听获取数数据
     * @param mixed $msg
     * @param int|null $worker_id
     * @return bool
     * @throws \Exception
     */
    public function sendMessage($msg = null, ?int $worker_id = null)
    {
        if (!$msg) {
            throw new SystemException('Missing msg params');
        }

        if ($worker_id === null) {
            $worker_id = $this->bindWorkerId;
        }

        return Swfy::getServer()->sendMessage($msg, $worker_id);
    }

    /**
     * 阻塞写数据
     * worker进程将通过swoole_client_select或者stream_select函数监听获取数数据
     * @param string $msg
     * @return int|false
     */
    public function write(string $msg)
    {
        $this->swooleProcess->write($msg);
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
                    BaseServer::catchException($throwable);
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
     * @return int
     */
    public function getCurrentCoroutineLastCid()
    {
        $coroutine_info = \Swoole\Coroutine::stats();
        return $coroutine_info['coroutine_last_cid'] ?? null;
    }

    /**
     * 对于运行态的协程，还没有执行完的，设置一个再等待时间$re_wait_time
     * @param int $cycle_times 轮询次数
     * @param float $re_wait_time 每次2s轮询
     */
    private function runtimeCoroutineWait(int $cycle_times = 5, float $re_wait_time = 2.0 )
    {
        if ($cycle_times <= 0) {
            $cycle_times = 2;
        }
        while ($cycle_times > 0) {
            // 当前运行的coroutine
            $runCoroutineNum = $this->getCurrentRunCoroutineNum();
            // 除了主协程和runtimeCoroutineWait跑在协程中，所以等于2个协程，还有其他协程没唤醒，则再等待
            if ($runCoroutineNum > 2) {
                --$cycle_times;
                \Swoole\Coroutine\System::sleep($re_wait_time);
            } else {
                break;
            }
        }
    }

    /**
     * init
     */
    public function init()
    {
    }

    /**
     * run
     * @return mixed
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
     * @return void
     */
    public function onReceive(mixed $msg, ...$args)
    {
    }

}