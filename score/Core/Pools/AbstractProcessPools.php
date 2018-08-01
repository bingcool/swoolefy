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

namespace Swoolefy\Core\Pools;

use Swoole\Process;
use Swoolefy\Core\Swfy;
use Swoolefy\Core\Table\TableManager;

abstract class AbstractProcessPools {
	protected $swooleProcess;
    protected $processName;
    protected $async = null;
    protected $args = [];
    protected $process_num = 1;
    protected $worker_id = 0;
    protected $polling = false;
    protected $master_process_name;

    /**
     * __construct 
     * @param string  $processName
     * @param boolean $async      
     * @param array   $args       
     */
    public function __construct(string $processName, $async = true, array $args = []) {
        $this->async = $async;
        $this->args = $args;
        list($process_num, $worker_id, $polling, $master_process_name) = $args;
        $this->process_num = $process_num;
        $this->processName = $processName;
        $this->worker_id = $worker_id;
        $this->polling = $polling;
        $this->master_process_name = $master_process_name;
        $this->swooleProcess = new \swoole_process([$this,'__start'], false, 2);
        $this->swooleProcess->start();
    }

    /**
     * getProcess 获取process进程对象
     * @return object
     */
    public function getProcess() {
        return $this->swooleProcess;
    }

    /*
     * 服务启动后才能获得到创建的进程pid
     */
    public function getPid() {
       $this->swooleProcess->pid;
    }

    /**
     * __start 创建process的成功回调处理
     * @param  Process $process
     * @return void
     */
    public function __start(Process $process) {
        TableManager::getTable('table_process_pools_map')->set(
            md5($this->processName), ['pid'=>$this->swooleProcess->pid]
        );

        TableManager::getTable('table_process_pools_number')->set($this->swooleProcess->pid, ['pnumber'=>$this->process_num, 'masterProcessName'=>$this->master_process_name]);

        if(extension_loaded('pcntl')) {
            pcntl_async_signals(true);
        }

        Process::signal(SIGTERM, function() use($process) {
            $this->onShutDown();
            TableManager::getTable('table_process_pools_map')->del(md5($this->processName));
            swoole_event_del($process->pipe);
            $this->swooleProcess->exit(0);
        });

        if($this->async){
            swoole_event_add($this->swooleProcess->pipe, function(){
                $msg = $this->swooleProcess->read(64 * 1024);
                $this->onReceive($msg);
            });
        }

        $this->swooleProcess->name('php-addProcessPools:'.$this->getProcessName());
        
        $this->run($this->swooleProcess);
    }

    /**
     * getArgs 获取变量参数
     * @param  boolean  $all true 返回所有的变量| false 只返回外部传入变量
     * @return mixed
     */
    public function getArgs(bool $all = false) {
        if($all) {
            return $this->args;
        }
        return end($this->args);
    }

    /**
     * getProcessName 
     * @return string 
     */
    public function getProcessName() {
        return $this->processName;
    }

    /**
     * sendMessage 向worker进程发送数据，worker进程将通过onPipeMessage函数监听获取数数据
     * 主要是用在writeByPolling函数，writeByPolling函数是异步函数，可以通过该函数通知worker进程任务完成
     * @param  mixed  $msg
     * @param  int    $worker_id
     * @return boolean
     */
    public function sendMessage($msg, $worker_id = null) {
        if(!is_numeric($worker_id) || is_null($worker_id)) {
           $worker_id = $this->worker_id; 
        }
        return Swfy::getServer()->sendMessage($msg, $worker_id);
    }

    /**
     * onFinish 子进程任务完成，默认返回进程名称，PoolsManager释放进程，主要是用在writeByPolling函数，writeByPolling函数是异步函数
     * @return   string
     */
    public function finish($msg = null, $worker_id = null) {
        if($this->polling) {
            // 异步任务完成后,send数据给worker，子进程任务完成
            if($msg) {
                $this->sendMessage($msg, $worker_id);
            }
            // 通知woker进程，子进程空闲
            $this->swooleProcess->write($this->processName);
        }
        return ;
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