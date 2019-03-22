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

use Swoole\Table;
use Swoole\Process;
use Swoolefy\Core\Swfy;
use Swoolefy\Core\Table\TableManager;

class PoolsManager {

    use \Swoolefy\Core\SingletonTrait;

    const PROCESS_NUM = 1024;
    
    private static $table_process = [
        // 进程内存表
        'table_process_pools_map' => [
            // 内存表建立的行数,取决于建立的process进程数,最小值1024
            'size' => self::PROCESS_NUM,
            // 字段
            'fields'=> [
                ['pid','int', 10],
                ['process_name','string', 50],
            ]
        ]
    ];

    private static $total_process_num = 0;

    private static $processList = [];

    private static $worker_num = 1;

    /**
     * __construct
     *
     * @param  $total_process //每个worker绑定的进程数，也即是为每个worker附加的自定义进程数，默认绑定一个process
     */
    private function __construct() {
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
            throw new \Exception("total self process num more then ".self::PROCESS_NUM, 1);
        }

        $key = md5($processName);
        if(isset(self::$processList[$key])) {
            throw new \Exception("you can not add the same process : $processName", 1);
        }

        if(isset($args[0]) &&  $args[0] instanceof \Swoole\Channel) {

            if(self::$worker_num != count($args)) {
                throw new \Exception("args of channel object num must == worker_num", 1);
            }

            for($i = 0; $i < self::$worker_num; $i++) {
                for($j = 0; $j < $process_num_bind_worker; $j++) {
                    try{
                        $process = new $processClass($processName.'@'.$i.'@'.$j, $async, [$args[$i]], $extend_data, $enable_coroutine);
                        $process->setBindWorkerId($i);
                        self::$processList[$key][$i][$j] = $process;
                    }catch (\Exception $e){
                        throw new \Exception($e->getMessage(), 1);
                    }
                }
            }
        }else {
            for($i = 0; $i < self::$worker_num; $i++) {
                for($j = 0; $j < $process_num_bind_worker; $j++) {
                    try{
                        $process = new $processClass($processName.'@'.$i.'@'.$j, $async, $args, $extend_data, $enable_coroutine);
                        $process->setBindWorkerId($i);
                        self::$processList[$key][$i][$j] = $process;
                    }catch (\Exception $e){
                        throw new \Exception($e->getMessage(), 1);
                    }
                }
            }
        }
    }

    /**
     * getProcessByName 通过名称获取绑定当前worker进程的某个进程
     * @param  string $processName
     * @param  bool $is_all 是否返回worker中绑定的所有process
     * @throws \Exception
     * @return mixed
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
     * @param  int    $pid
     * @throws \Exception
     * @return mixed
     */
    public static function getProcessPoolsByPid(int $pid) {
        if(Swfy::isWorkerProcess()) {
            $table = TableManager::getTable('table_process_pools_map');
            foreach($table as $key => $item) {
                if($item['pid'] == $pid) {
                    list($processName, $worker_id, $process_num) = explode('@', $item['process_name']);
                    $key = md5($processName);
                    return self::$processList[$key][$worker_id][$process_num];
                }
            }
            return null;
        }else {
            throw new \Exception("PoolsManager::getInstance() can not use in task or self process, only use in worker process!", 1);
        }
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
     * @param  string  $processName
     * @param  boolean $is_restart_all_process
     * @return boolean
     */
    public static function rebootPools(string $processName, bool $is_restart_all_process = false) {
        if($is_restart_all_process) {
            foreach(self::$worker_num as $key => $worker_processes) {
                foreach($worker_processes as $worker_id => $all_processes) {
                    foreach($all_processes as $k => $process) {
                        $kill_flag = $process->getSwoolefyProcessKillFlag();
                        $process->getProcess()->write($kill_flag);
                    }
                }
            }
            return true;
        }
        $all_processes = self::getProcessPoolsByName($processName, true);
        if(is_array($all_processes) && count($all_processes) > 0) {
            foreach($all_processes as $k => $process) {
                $kill_flag = $process->getSwoolefyProcessKillFlag();
                $process->getProcess()->write($kill_flag);
            }
            return true;
        }

    }

    /**
     * writeByProcessName 向绑定当前worker进程的某个自定义进程写数据
     * @param  string $name
     * @param  string $data
     * @return boolean
     */
    public static function writeByProcessPoolsName(string $processName, string $data) {
        $process = self::getProcessPoolsByName($processName);
        if($process){
            return (bool)$process->getProcess()->write($data);
        }else{
            return false;
        }
    }

    /**
     * readByProcessName 读取绑定的某个进程数据
     * @param  string $name
     * @param  float  $timeOut
     * @return mixed
     */
    public static function readByProcessPoolsName(string $processName, float $timeOut = 0.1) {
        $process = self::getProcessPoolsByName($processName);
        if($process){
            $process = $process->getProcess();
            $read = array($process);
            $write = [];
            $error = [];
            $ret = swoole_select($read, $write, $error, $timeOut);
            if($ret){
                return $process->read(64 * 1024);
            }else{
                return null;
            }
        }else{
            return null;
        }
    }
}