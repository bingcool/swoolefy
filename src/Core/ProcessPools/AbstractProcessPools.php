<?php
/**
+----------------------------------------------------------------------
| swoolefy framework bases on swoole extension development, we can use it easily!
+----------------------------------------------------------------------
| Licensed ( https://opensource.org/licenses/MIT )
+----------------------------------------------------------------------
| @see https://github.com/bingcool/swoolefy
+----------------------------------------------------------------------
*/

namespace Swoolefy\Core\ProcessPools;

use Swoole\Process;
use Swoolefy\Core\Swfy;
use Swoolefy\Core\BaseServer;
use Swoole\Coroutine\Channel;
use Swoolefy\Core\EventController;
use Swoolefy\Core\Table\TableManager;

abstract class AbstractProcessPools {

    private $swooleProcess;
    private $process_name;
    private $async = null;
    private $args = [];
    private $extend_data;
    private $bind_worker_id = null;
    private $enable_coroutine = true;
    private $is_exiting = false;

    const SWOOLEFY_PROCESS_KILL_FLAG = "action::restart::action::reboot";

    /**
     * AbstractProcessPools constructor.
     * @param string $process_name
     * @param bool   $async
     * @param array  $args
     * @param mixed  $extend_data
     * @param boolean   $enable_coroutine
     */
    public function __construct(string $process_name, bool $async = true, array $args = [], $extend_data = null, bool $enable_coroutine = true) {
        $this->async = $async;
        $this->args = $args;
        $this->extend_data = $extend_data;
        $this->process_name = $process_name;
        $this->enable_coroutine = true;
        if(version_compare(swoole_version(),'4.3.0','>=')) {
            $this->swooleProcess = new \Swoole\Process([$this,'__start'], false, 2, $enable_coroutine);
        }else {
            $this->swooleProcess = new \Swoole\Process([$this,'__start'], false, 2);
        }
        Swfy::getServer()->addProcess($this->swooleProcess);
    }

    /**
     * getProcess 获取process进程对象
     * @return Process
     */
    public function getProcess() {
        return $this->swooleProcess;
    }

    /*
     * 服务启动后才能获得到创建的进程pid,不启动为null
     * @return mixed
     */
    public function getPid() {
        $pid = TableManager::getTable('table_process_pools_map')->get(md5($this->process_name),'pid');
        if($pid) {
            return $pid;
        }
        return null;
    }

    /**
     * @return string
     */
    public function getSwoolefyProcessKillFlag() {
        return static::SWOOLEFY_PROCESS_KILL_FLAG;
    }

    /**
     * setBindWorkerId 进程绑定对应的worker 
     * @param  int $worker_id
     * @return void
     */
    public function setBindWorkerId(int $worker_id) {
        $this->bind_worker_id = $worker_id;    
    }

    /**
     * getBindWorkerId 获取绑定的worker_id
     * @return int
     */
    public function getBindWorkerId() {
        return $this->bind_worker_id;
    }

    /**
     * __start 创建process的成功回调处理
     * @param  Process $process
     * @return void
     */
    public function __start(Process $process) {
        TableManager::getTable('table_process_pools_map')->set(
            md5($this->process_name), ['pid'=>$this->swooleProcess->pid, 'process_name'=>$this->process_name]
        );
        if(extension_loaded('pcntl')) {
            pcntl_async_signals(true);
        }

        Process::signal(SIGTERM, function() use($process) {
            TableManager::getTable('table_process_pools_map')->del(md5($this->process_name));
            \Swoole\Event::del($process->pipe);
            \Swoole\Event::exit();
            $this->swooleProcess->exit(0);
        });

        if($this->async) {
            \Swoole\Event::add($this->swooleProcess->pipe, function(){
                $msg = $this->swooleProcess->read(64 * 1024);
                \Swoole\Coroutine::create(function() use($msg) {
                    try{
                        if($msg == static::SWOOLEFY_PROCESS_KILL_FLAG) {
                            $this->reboot();
                            return;
                        }else {
                            $message = json_decode($msg, true) ?? $msg;
                            (new \Swoolefy\Core\EventApp)->registerApp(function(EventController $eventApp) use($message) {
                                $this->onReceive($message);
                            });
                        }
                    }catch(\Throwable $throwable) {
                        BaseServer::catchException($throwable);
                    }
                });
            });
        }

        $this->swooleProcess->name(BaseServer::getAppPrefix().':'.'php-swoolefy-user-process-worker'.$this->bind_worker_id.':'.$this->getProcessName(true));
        try {
            (new \Swoolefy\Core\EventApp)->registerApp(function(EventController $eventApp) {
                $this->init();
                $this->run();
            });
        }catch(\Throwable $t) {
            BaseServer::catchException($t);
        }
    }

    /**
     * getArgs 获取变量参数
     * @return mixed
     */
    public function getArgs() {
        return $this->args;
    }

    /**
     * @return null
     */
    public function getExtendData() {
        return $this->extend_data;
    }

    /**
     * getProcessName
     * @param  boolean $is_full_name
     * @return string 
     */
    public function getProcessName(bool $is_full_name = false) {
        if(!$is_full_name) {
            list($process_name, $worker_id, $process_num) = explode('@', $this->process_name);
            return $process_name;
        }
        return $this->process_name;
    }

    /**
     * 是否启用协程
     * @return bool
     */
    public function isEnableCoroutine() {
        return $this->enable_coroutine;
    }

    /**
     * sendMessage 向绑定的worker进程发送数据
     * worker进程将通过onPipeMessage函数异步监听获取数数据
     * @param  mixed  $msg
     * @param  int    $worker_id
     * @throws \Exception
     * @return boolean
     */
    public function sendMessage($msg = null, int $worker_id = null) {
        if(!$msg) {
            throw new \Exception('Param of msg can not be null or empty');
        }
        if($worker_id == null) {
            $worker_id = $this->bind_worker_id;
        }
        return Swfy::getServer()->sendMessage($msg, $worker_id);
    }

    /**
     * 阻塞写数据
     * worker进程将通过swoole_client_select或者stream_select函数监听获取数数据
     * @param $msg
     * @return string
     */
    public function write($msg) {
        $this->swooleProcess->write($msg);
    }

    /**
     * reboot
     * @return void
     */
    public function reboot() {
        if(!$this->is_exiting) {
            $this->is_exiting = true;
            $channel = new Channel(1);
            \Swoole\Coroutine::create(function() {
                try {
                    $this->runtimeCoroutineWait();
                    (new \Swoolefy\Core\EventApp)->registerApp(function(EventController $eventApp) {
                        $this->onShutDown();
                    });
                }catch (\Throwable $throwable) {
                    BaseServer::catchException($throwable);
                }finally {
                    \Swoole\Process::kill($this->getPid(), SIGTERM);
                }
            });
            // 需要阻塞等待，防止父协程继续往下执行
            $channel->pop(-1);
        }
    }

    /**
     * @return bool
     */
    public function isExiting() {
        return $this->is_exiting;
    }

    /**
     * getCurrentRunCoroutineNum 获取当前进程中正在运行的协程数量，可以通过这个值判断比较，防止协程过多创建，可以设置sleep等待
     * @return int
     */
    public function getCurrentRunCoroutineNum() {
        $coroutine_info = \Swoole\Coroutine::stats();
        return $coroutine_info['coroutine_num'] ?? null;
    }

    /**
     * getCurrentCoroutineLastCid 获取当前进程的协程cid已分配到哪个值，可以根据这个值设置进程reboot,防止cid超出最大数
     * @return int
     */
    public function getCurrentCoroutineLastCid() {
        $coroutine_info = \Swoole\Coroutine::stats();
        return $coroutine_info['coroutine_last_cid'] ?? null;
    }

    /**
     * 对于运行态的协程，还没有执行完的，设置一个再等待时间$re_wait_time
     * @param int $cycle_times 轮询次数
     * @param int $re_wait_time 每次2s轮询
     */
    private function runtimeCoroutineWait(int $cycle_times = 5, int $re_wait_time = 2) {
        if($cycle_times <= 0) {
            $cycle_times = 2;
        }
        while($cycle_times > 0) {
            // 当前运行的coroutine
            $runCoroutineNum = $this->getCurrentRunCoroutineNum();
            // 除了主协程和runtimeCoroutineWait跑在协程中，所以等于2个协程，还有其他协程没唤醒，则再等待
            if($runCoroutineNum > 2) {
                --$cycle_times;
                \Swoole\Coroutine::sleep($re_wait_time);
            }else {
                break;
            }
        }
    }

    /**
     * init
     */
    public function init() {}

    /**
     * run 进程创建后的run方法
     * @return void
     */
    abstract public function run();

    /**
     * @return void
     */
    public function onShutDown() {}

    /**
     * @param mixed $msg
     * @param mixed ...$args
     * @return void
     */
    public function onReceive($msg, ...$args) {}

}