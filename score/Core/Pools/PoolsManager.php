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
use Swoolefy\Core\Timer\TickManager;
use Swoolefy\Core\Table\TableManager;

class PoolsManager {

    use \Swoolefy\Core\SingletonTrait;
    
    private static $table_process = [
        // 进程内存表
        'table_process_pools_map' => [
            // 内存表建立的行数,取决于建立的process进程数
            'size' => 256,
            // 字段
            'fields'=> [
                ['pid','int', 10]
            ]
        ]
    ];

    private static $total_process_num = null;

    private static $processList = [];

    /**
     * __construct
     * @param  $total_process 进程池总数
     */
    private function __construct() {
        $conf = Swfy::getConf();
        if(isset($conf['setting']['worker_num'])) {
            $total_process_num = $conf['setting']['worker_num'];
            self::$table_process['table_process_pools_map']['size'] = $total_process_num;
            self::$total_process_num = $total_process_num;
        }
        TableManager::getInstance()->createTable(self::$table_process);
    }

    /**
     * addProcess 添加创建进程
     * @param string  $processName
     * @param string  $processClass
     * @param int     $processNumber
     * @param boolean $polling  是否是轮训向空闲进程写数据
     * @param int     $timer_int 定时器时间，单位毫秒
     * @param boolean $async
     * @param array   $args
     * @return  void
     */
    public static function addProcessPools(string $processName, string $processClass, $async = true, array $args = []) {
        if(!TableManager::isExistTable('table_process_pools_map')) {
            TableManager::getInstance()->createTable(self::$table_process);
        }
        for($i=0; $i<self::$total_process_num; $i++) {
            $process_name = $processName.$i;
            $key = md5($process_name);
            if(!isset(self::$processList[$key])) {
                try{
                    $process = new $processClass($processName, $async, [$args[$i]]);
                    $process->setBindWorkerId($i);
                    self::$processList[$key] = $process;
                }catch (\Exception $e){
                    throw new \Exception($e->getMessage(), 1);       
                }
            }else{
                throw new \Exception("you can not add the same process : $processName", 1);
            }
        }
        
    }

    /**
     * getProcessByName 通过名称获取一个进程
     * @param  string $processName
     * @return object
     */
    public static function getProcessPoolsByName(string $processName) {
        if(Swfy::isWorkerProcess()) {
            $worker_id = Swfy::getCurrentWorkerId();
            $key = md5($processName.$worker_id);
            if(isset(self::$processList[$key])) {
                return self::$processList[$key];
            }else {
                return null;
            }
        }else {
            throw new \Exception("PoolsManager::getInstance() can not use in task or self process", 1);  
        }
    }

    /**
     * getProcessByPid 通过进程id获取进程
     * @param  int    $pid
     * @return object
     */
    public static function getProcessPoolsByPid(int $pid) {
        $table = TableManager::getTable('table_process_pools_map');
        foreach ($table as $key => $item) {
            if($item['pid'] == $pid){
                return self::$processList[$key];
            }
        }
        return null;
    }

    /**
     * setProcess 设置一个进程
     * @param string          $processName
     * @param AbstractProcess $process
     */
    public static function setProcessPools(string $processName, AbstractProcess $process) {
        $worker_id = Swfy::getCurrentWorkerId();
        $key = md5($processName.$worker_id);
        self::$processList[$key] = $process;
    }

    /**
     * reboot 重启某个进程
     * @param  string $processName
     * @return boolean
     */
    public static function rebootPools(string $processName) {
        $p = self::getProcessPoolsByName($processName);
        if($p){
            \Swoole\Process::kill($p->getPid(), SIGTERM);
            return true;
        }else{
            return false;
        }
    }

    /**
     * writeByProcessName 向某个进程写数据
     * @param  string $name
     * @param  string $data
     * @return boolean
     */
    public static function writeByProcessPoolsName(string $name, string $data) {
        $process = self::getProcessPoolsByName($name);
        if($process){
            return (bool)$process->getProcess()->write($data);
        }else{
            return false;
        }
    }

    /**
     * readByProcessName 读取某个进程数据
     * @param  string $name
     * @param  float  $timeOut
     * @return mixed
     */
    public static function readByProcessPoolsName(string $name, float $timeOut = 0.1) {
        $process = self::getProcessPoolsByName($name);
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