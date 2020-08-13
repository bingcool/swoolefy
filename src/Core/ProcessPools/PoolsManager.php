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
use Swoolefy\Core\Table\TableManager;

class PoolsManager {

    use \Swoolefy\Core\SingletonTrait;

    const PROCESS_NUM = 1024;
    
    private $table_process = [
        // 进程内存表
        'table_process_pools_map' => [
            // 内存表建立的行数,取决于建立的process进程数,最小值64
            'size' => self::PROCESS_NUM,
            // 字段
            'fields'=> [
                //从4.3版本开始，底层对内存长度做了对齐处理。字符串长度必须为8的整数倍。如长度为18会自动对齐到24字节
                ['pid','int', 10],
                ['process_name','string', 56],
            ]
        ]
    ];

    private $total_process_num = 0;

    private $processList = [];

    private $worker_num = 1;

    /**
     * __construct
     *每个worker绑定的进程数，也即是为每个worker附加的自定义进程数，默认绑定一个process
     */
    protected function __construct() {
        $conf = Swfy::getConf();
        if(isset($conf['setting']['worker_num'])) {
            $this->worker_num = $conf['setting']['worker_num'];
        }
        TableManager::getInstance()->createTable($this->table_process);
    }

    /**
     * addProcess 添加创建进程并绑定当前worker进程
     * @param string  $processName
     * @param string  $processClass
     * @param int     $process_num_bind_worker 每个worker绑定的进程数，也即是为每个worker附加的自定义进程数，默认绑定一个process
     * @param boolean $async
     * @param array   $args
     * @param mixed   $extend_data
     * @param boolean $enable_coroutine
     * @throws Exception
     * @return void
     */
    public function addProcessPools(
        string $processName,
        string $processClass,
        int $process_num_bind_worker = 1,
        bool $async = true,
        array $args = [],
        $extend_data = null,
        bool $enable_coroutine = true
    ) {
        if(!TableManager::isExistTable('table_process_pools_map')) {
            TableManager::getInstance()->createTable($this->table_process);
        }
        // total process num
        $this->total_process_num += ($this->worker_num * $process_num_bind_worker);

        if($this->total_process_num > self::PROCESS_NUM) {
            throw new \Exception("PoolsManager Error : total self process num more then ".self::PROCESS_NUM);
        }

        $key = md5($processName);
        if(isset($this->processList[$key])) {
            throw new \Exception("PoolsManager Error : you can not add the same process : $processName");
        }

        for($i = 0; $i < $this->worker_num; $i++) {
            for($j = 0; $j < $process_num_bind_worker; $j++) {
                try{
                    /**@var AbstractProcessPools $process */
                    $process = new $processClass($processName.'@'.$i.'@'.$j, $async, $args, $extend_data, $enable_coroutine);
                    $process->setBindWorkerId($i);
                    $this->processList[$key][$i][$j] = $process;
                }catch (\Exception $exception) {
                    throw $exception;
                }
            }
        }
    }

    /**
     * getProcessByName 通过名称获取绑定当前worker进程的某个进程
     * @param  string $processName
     * @param  bool $is_all 是否返回worker中绑定的所有process
     * @return AbstractProcessPools|array
     * @throws Exception
     */
    public function getProcessPoolsByName(string $processName, bool $is_all = false) {
        if(!Swfy::isWorkerProcess()) {
            throw new \Exception("PoolsManager::getInstance() can not use in task or self process, only use in worker process");
        }
        $worker_id = Swfy::getCurrentWorkerId();
        $key = md5($processName);
        if(isset($this->processList[$key][$worker_id])) {
            if($is_all) {
                return $this->processList[$key][$worker_id];
            }
            // 随机返回当前worker进程绑定的一个附属进程
            $k = array_rand($this->processList[$key][$worker_id]);
            return $this->processList[$key][$worker_id][$k];
        }
        return null;
    }

    /**
     * getProcessByPid 通过进程id获取绑定当前worker进程的某个进程
     * @param  int $pid
     * @throws Exception
     * @return mixed
     */
    public function getProcessPoolsByPid(int $pid) {
        if(!Swfy::isWorkerProcess()) {
            throw new \Exception("PoolsManager::getInstance() can not use in task or self process, only use in worker process");
        }
        $table = TableManager::getTable('table_process_pools_map');
        foreach($table as $key => $item) {
            if($item['pid'] == $pid) {
                list($processName, $worker_id, $process_num) = explode('@', $item['process_name']);
                $key = md5($processName);
                return $this->processList[$key][$worker_id][$process_num];
            }
        }
        return null;
    }

    /**
     * setProcess 绑定当前worker进程的设置一个进程
     * @param string          $processName
     * @param AbstractProcessPools $process
     */
    public function setProcessPools(string $processName, AbstractProcessPools $process) {
        $worker_id = Swfy::getCurrentWorkerId();
        $key = md5($processName);
        array_push($this->processList[$key][$worker_id], $process);
    }

    /**
     * reboot 重启进程
     * @param string $processName
     * @param boolean $is_restart_all_process
     * @return boolean
     * @throws Exception
     */
    public function rebootPools(string $processName, bool $is_restart_all_process = false) {
        if($is_restart_all_process) {
            foreach($this->worker_num as $key => $worker_processes) {
                foreach($worker_processes as $worker_id => $all_processes) {
                    /**@var AbstractProcessPools $process */
                    foreach($all_processes as $k => $process) {
                        $kill_flag = $process->getSwoolefyProcessKillFlag();
                        $process->getProcess()->write($kill_flag);
                    }
                }
            }
            return true;
        }
        $allProcesses = $this->getProcessPoolsByName($processName, true);
        if(is_array($allProcesses) && count($allProcesses) > 0) {
            foreach($allProcesses as $k => $process) {
                /**@var AbstractProcessPools $process */
                $kill_flag = $process->getSwoolefyProcessKillFlag();
                $process->getProcess()->write($kill_flag);
            }
            return true;
        }

    }

    /**
     * writeByProcessName 向绑定当前worker进程的某个自定义进程写数据
     * 如果需要获取等待的结果，可以设置callback,在规定时间内读取返回数据回调处理
     * @param string $name
     * @param mixed $data
     * @param \Closure $callback
     * @param float $timeOut
     * @return Process
     * @throws Exception
     */
    public function writeByProcessPoolsName(string $processName, $data, \Closure $callback = null, $timeOut = 3) {
        $process = $this->getProcessPoolsByName($processName);
        if($process){
            if(is_array($data)) {
                $data = json_encode($data, JSON_UNESCAPED_UNICODE);
            }
            $result = (bool)$process->getProcess()->write($data);
            if($result && $callback instanceof \Closure) {
                $msg = null;
                $msg = $this->read($process->getProcess(), $timeOut);
                $callback->call($this, $msg);
            }
        }
        return $process->getProcess();
    }

    /**
     * 在规定时间内读取数据
     * @param Process $swooleProcess
     * @param float $timeOut
     * @return mixed
     */
    public function read(Process $swooleProcess, float $timeOut = 3) {
        $result = null;
        $read = [$swooleProcess];
        $write = [];
        $error = [];
        $ret = swoole_select($read, $write, $error, $timeOut);
        if($ret){
            $result = $swooleProcess->read(64 * 1024);
        }
        return $result;
    }
}