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
	private $swooleProcess;
    private $processName;
    private $async = null;
    private $args = [];
    private $process_num = 1;

    /**
     * __construct 
     * @param string  $processName
     * @param boolean $async      
     * @param array   $args       
     */
    public function __construct(string $processName, $async = true, array $args = [], $process_num = 1) {
        $this->async = $async;
        $this->args = $args;
        $this->process_num = $process_num;
        $this->processName = $processName;
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

        TableManager::getTable('table_process_pools_number')->set($this->swooleProcess->pid, ['pnumber'=>$this->process_num]);

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
     * @return mixed
     */
    public function getArgs() {
        return $this->args;
    }

    /**
     * getProcessName 
     * @return string 
     */
    public function getProcessName() {
        return $this->processName;
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