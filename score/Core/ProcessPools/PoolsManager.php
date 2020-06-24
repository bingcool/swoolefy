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

namespace Swoolefy\Core\ProcessPools;

use Swoole\Table;
use Swoole\Process;
use Swoolefy\Core\Process\AbstractProcess;
use Swoolefy\Core\Swfy;
use Swoolefy\Core\Table\TableManager;

class PoolsManager {

    use \Swoolefy\Core\SingletonTrait;

    const PROCESS_NUM = 1024;
    
    private static $table_process = [
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

    private static $total_process_num = 0;

    private static $processList = [];

    private static $worker_num = 1;

    /**
     * __construct
     *每个worker绑定的进程数，也即是为每个worker附加的自定义进程数，默认绑定一个process
     */
    protected function __construct() {
        $conf = Swfy::getConf();
        if(isset($conf['setting']['worker_num'])) {
            self::$worker_num = $conf['setting']['worker_num'];
        }
        TableManager::getInstance()->createTable(self::$table_process);
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
     * @throws \Exception
     * @return void
     */
    public static function addProcessPools(string $processName, string $processClass, int $process_num_bind_worker = 1, bool $async = true, array $args = [], $extend_data = null, bool $enable_coroutine = false) {
        if(!TableManager::isExistTable('table_process_pools_map')) {
            TableManager::getInstance()->createTable(self::$table_process);
        }
        // total process num
        self::$total_process_num += (self::$worker_num * $process_num_bind_worker);

        if(self::$total_process_num > self::PROCESS_NUM) {
            throw new \Exception("PoolsManager Error : total self process num more then ".self::PROCESS_NUM, 1);
        }

        $key = md5($processName);
        if(isset(self::$processList[$key])) {
            throw new \Exception("PoolsManager Error : you can not add the same process : $processName", 1);
        }

        for($i = 0; $i < self::$worker_num; $i++) {
            for($j = 0; $j < $process_num_bind_worker; $j++) {
                try{
                    /**
                     * @var AbstractProcessPools $process
                     */
                    $process = new $processClass($processName.'@'.$i.'@'.$j, $async, $args, $extend_data, $enable_coroutine);
                    $process->setBindWorkerId($i);
                    self::$processList[$key][$i][$j] = $process;
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
     * @return mixed
     * @throws \Exception
     */
    public static function getProcessPoolsByName(string $processName, bool $is_all = false) {
        if(Swfy::isWorkerProcess()) {
            $worker_id = Swfy::getCurrentWorkerId();
            $key = md5($processName);
            if(isset(self::$processList[$key][$worker_id])) {
                if($is_all) {
                    return self::$processList[$key][$worker_id];
                }
                $k = array_rand(self::$processList[$key][$worker_id]);
                return self::$processList[$key][$worker_id][$k];
            }else {
                return null;
            }
        }else {
            throw new \Exception("PoolsManager::getInstance() can not use in task or self process, only use in worker process!", 1);
        }
    }

    /**
     * getProcessByPid 通过进程id获取绑定当前worker进程的某个进程
     * @param  int $pid
     * @throws \Exception
     * @return mixed
     */
    public static function getProcessPoolsByPid(int $pid) {
        if(!Swfy::isWorkerProcess()) {
            throw new \Exception("PoolsManager::getInstance() can not use in task or self process, only use in worker process");
        }

        $table = TableManager::getTable('table_process_pools_map');
        foreach($table as $key => $item) {
            if($item['pid'] == $pid) {
                list($processName, $worker_id, $process_num) = explode('@', $item['process_name']);
                $key = md5($processName);
                return self::$processList[$key][$worker_id][$process_num];
            }
        }
        return null;
    }

    /**
     * setProcess 绑定当前worker进程的设置一个进程
     * @param string          $processName
     * @param AbstractProcessPools $process
     */
    public static function setProcessPools(string $processName, AbstractProcessPools $process) {
        $worker_id = Swfy::getCurrentWorkerId();
        $key = md5($processName);
        array_push(self::$processList[$key][$worker_id], $process);
    }

    /**
     * reboot 重启进程
     * @param string $processName
     * @param boolean $is_restart_all_process
     * @return boolean
     * @throws \Exception
     */
    public static function rebootPools(string $processName, bool $is_restart_all_process = false) {
        if($is_restart_all_process) {
            foreach(self::$worker_num as $key => $worker_processes) {
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
        $allProcesses = self::getProcessPoolsByName($processName, true);
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
     * @param string $name
     * @param mixed $data
     * @return boolean
     * @throws \Exception
     */
    public static function writeByProcessPoolsName(string $processName, $data) {
        $process = self::getProcessPoolsByName($processName);
        if($process){
            if(is_array($data)) {
                $data = json_encode($data, JSON_UNESCAPED_UNICODE);
            }
            return (bool)$process->getProcess()->write($data);
        }
        return false;
    }

    /**
     * readByProcessName 读取绑定的某个进程数据
     * @param  string $name
     * @param  float  $timeOut
     * @throws \Exception
     * @return mixed
     */
    public static function readByProcessPoolsName(string $processName, float $timeOut = 0.1) {
        $process = self::getProcessPoolsByName($processName);
        if(!is_object($process)){
            throw new \Exception("Not exist name of $processName process");
        }
        $swooleProcess = $process->getProcess();
        $read = array($swooleProcess);
        $write = [];
        $error = [];
        $ret = swoole_select($read, $write, $error, $timeOut);
        if($ret){
            return $process->read(64 * 1024);
        }
    }
}