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

    const SWOOLEFY_PROCESS_KILL_FLAG = "action::restart::swoolefy_process_kill_flag";

    /**
     * __construct 
     * @param string  $processName
     * @param boolean $async      
     * @param array   $args       
     */
    public function __construct(string $processName, $async = true, array $args = [], $extend_data = null) {
        $this->async = $async;
        $this->args = $args;
        $this->extend_data = $extend_data;
        $this->processName = $processName;
        $this->swooleProcess = new \Swoole\Process([$this,'__start'], false, 2);
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
            $this->onShutDown();
            TableManager::getTable('table_process_map')->del(md5($this->processName));
            swoole_event_del($process->pipe);
            $this->swooleProcess->exit(0);
        });

        if($this->async){
            swoole_event_add($this->swooleProcess->pipe, function(){
                $msg = $this->swooleProcess->read(64 * 1024);
                try{
                    if($msg == self::SWOOLEFY_PROCESS_KILL_FLAG) {
                        $this->reboot();
                        return;
                    }else {
                        $this->onReceive($msg);
                    }
                }catch(\Throwable $t) {
                    // 记录错误与异常
                    $exceptionHanderClass = BaseServer::getExceptionClass();
                    $errMsg = $t->getMessage();
                    $exceptionHanderClass::shutHalt($errMsg);
                }
            });
        }

        $this->swooleProcess->name('php-addSelfProcess:'.$this->getProcessName());
        try{
            $this->run($this->swooleProcess);
        }catch(\Throwable $t) {
            // 记录错误与异常
            $exceptionHanderClass = BaseServer::getExceptionClass();
            $errMsg = $t->getMessage();
            $exceptionHanderClass::shutHalt($errMsg);
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
     * @return string 
     */
    public function getProcessName() {
        return $this->processName;
    }

    /**
     * sendMessage 向worker进程发送数据(包含task进程)，worker进程将通过onPipeMessage函数监听获取数数据，默认向worker0发送
     * @param  mixed  $msg
     * @param  int    $worker_id
     * @throws
     * @return boolean
     */
    public function sendMessage($msg = null, int $worker_id = 0) {
        if($worker_id >= 1) {
            $worker_task_total_num = (int)Swfy::getServer()->setting['worker_num'] + (int)Swfy::getServer()->setting['task_worker_num'];
            if($worker_id >= $worker_task_total_num) {
                throw new \Exception("worker_id must less than $worker_task_total_num", 1);
            }
        }
        if(!$msg) {
            throw new \Exception('param $msg can not be null or empty', 1);   
        }
        return Swfy::getServer()->sendMessage($msg, $worker_id);
    }

    /**
     * reboot
     */
    public function reboot() {
        \Swoole\Process::kill($this->getPid(), SIGTERM);
    }

    /**
     * run 进程创建后的run方法
     * @param  Process $process
     * @return void
     */
    public abstract function run(Process $process);
    public abstract function onShutDown();
    public abstract function onReceive($str, ...$args);

}