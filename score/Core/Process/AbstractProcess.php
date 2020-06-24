<?php
/**
+----------------------------------------------------------------------
| swoolefy framework bases on swoole extension development, we can use it easily!
+----------------------------------------------------------------------
| Licensed ( https://opensource.org/licenses/MIT )
+----------------------------------------------------------------------
| Author: bingcool <bingcoolhuang@gmail.com || 2437667702@qq.com>
+----------------------------------------------------------------------
*/

namespace Swoolefy\Core\Process;

use Swoole\Process;
use Swoolefy\Core\Swfy;
use Swoolefy\Core\BaseServer;
use Swoolefy\Core\Table\TableManager;

abstract class AbstractProcess {

    private $swooleProcess;
    private $processName;
    private $async = null;
    private $args = [];
    private $extend_data;
    private $enable_coroutine = true;
    private $is_exiting = false;

    const SWOOLEFY_PROCESS_KILL_FLAG = "action::restart::action::reboot";

    /**
     * AbstractProcess constructor.
     * @param string $processName
     * @param bool   $async
     * @param array  $args
     * @param null   $extend_data
     * @param bool   $enable_coroutine
     */
    public function __construct(
        string $processName,
        bool $async = true,
        array $args = [],
        $extend_data = null,
        bool $enable_coroutine = true
    ){
        $this->async = $async;
        $this->args = $args;
        $this->extend_data = $extend_data;
        $this->processName = $processName;
        $this->enable_coroutine = $enable_coroutine;
        if(version_compare(swoole_version(),'4.4.5','>=')) {
            $this->swooleProcess = new \Swoole\Process([$this,'__start'], false, 2, $enable_coroutine);
        }else {
            $this->swooleProcess = new \Swoole\Process([$this,'__start'], false, 2);
        }
        Swfy::getServer()->addProcess($this->swooleProcess);
    }

    /**
     * getProcess 获取process进程对象
     * @return object
     */
    public function getProcess() {
        return $this->swooleProcess;
    }

    /*
     * 服务启动后才能获得到创建的进程pid,不启动为null
     */
    public function getPid() {
        $pid = TableManager::getTable('table_process_map')->get(md5($this->processName),'pid');
        if($pid) {
            return $pid;
        }
        return null;
    }

    /**
     * @return string
     */
    public function getSwoolefyProcessKillFlag() {
        return self::SWOOLEFY_PROCESS_KILL_FLAG;
    }

    /**
     * __start 创建process的成功回调处理
     * @param  Process $process
     * @return void
     */
    public function __start(Process $process) {
        TableManager::getTable('table_process_map')->set(
            md5($this->processName), ['pid'=>$this->swooleProcess->pid]
        );

        if(extension_loaded('pcntl')) {
            pcntl_async_signals(true);
        }

        Process::signal(SIGTERM, function() use($process) {
            try{
                $this->onShutDown();
            }catch (\Throwable $t) {
                BaseServer::catchException($t);
            }

            TableManager::getTable('table_process_map')->del(md5($this->processName));
            \Swoole\Event::del($process->pipe);
            \Swoole\Event::exit();
            $this->swooleProcess->exit(0);
        });

        if($this->async){
            \Swoole\Event::add($this->swooleProcess->pipe, function(){
                $msg = $this->swooleProcess->read(64 * 1024);
                try{
                    if($msg == self::SWOOLEFY_PROCESS_KILL_FLAG) {
                        $this->reboot();
                        return;
                    }else {
                        $msg = $this->validDataJson($msg);
                        $this->onReceive($msg);
                    }
                }catch(\Throwable $t) {
                    BaseServer::catchException($t);
                }
            });
        }

        $this->swooleProcess->name('php-swoolefy-user-process:'.$this->getProcessName());

        try{
            $this->init();
            $this->run();
        }catch(\Throwable $t) {
            BaseServer::catchException($t);
        }
        
    }

    /**
     * @param $data
     * @return array|mixed
     */
    protected function validDataJson($data) {
        if(is_string($data)) {
            $dataArr = json_decode($data, true);
            if(is_array($dataArr)) {
                $data = $dataArr;
            }
        }
        return $data;
    }

    /**
     * getArgs 获取变量参数
     * @return mixed
     */
    public function getArgs() {
        return $this->args;
    }

    /**
     * @return mixed
     */
    public function getExtendData() {
        return $this->extend_data;
    }

    /**
     * getProcessName 
     * @return string 
     */
    public function getProcessName() {
        return $this->processName;
    }

    /**
     * 是否启用协程
     * @param boolean
     */
    public function isEnableCoroutine() {
        return $this->enable_coroutine;
    }

    /**
     * sendMessage 向worker进程发送数据(包含task进程)，worker进程将通过onPipeMessage函数监听获取数数据，默认向worker0发送
     * @param  mixed  $msg
     * @param  int    $worker_id
     * @throws \Exception
     * @return boolean
     */
    public function sendMessage($msg = null, int $worker_id = 0) {
        if($worker_id >= 1) {
            $worker_task_total_num = (int)Swfy::getServer()->setting['worker_num'] + (int)Swfy::getServer()->setting['task_worker_num'];
            if($worker_id >= $worker_task_total_num) {
                throw new \Exception("Worker_id must less than $worker_task_total_num", 1);
            }
        }
        if(!$msg) {
            throw new \Exception('Param $msg can not be null or empty', 1);
        }
        return Swfy::getServer()->sendMessage($msg, $worker_id);
    }

    /**
     * reboot
     */
    public function reboot() {
        if(!$this->is_exiting) {
            $this->is_exiting = true;
            $this->runtimeCoroutineWait();
            \Swoole\Process::kill($this->getPid(), SIGTERM);
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
        while($cycle_times) {
            // 当前运行的coroutine
            $runCoroutineNum = $this->getCurrentRunCoroutineNum();
            // 除了主协程，还有其他协程没唤醒，则再等待
            if($runCoroutineNum > 1) {
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
     * @return mixed
     */
    public function onShutDown() {}

    /**
     * @param mixed $msg
     * @param mixed ...$args
     * @return mixed
     */
    public function onReceive($msg, ...$args) {}

}