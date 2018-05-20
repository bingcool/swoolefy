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
use Swoole\Table;
use Swoolefy\Core\Table\TableManager;

class PoolsManager {

    use \Swoolefy\Core\SingleTrait;
	
	private static $table_process = [
		// 进程内存表
		'table_process_pools_map' => [
			// 内存表建立的行数,取决于建立的process进程数
			'size' => 256,
			// 字段
			'fields'=> [
				['pid','int', 10]
			]
		],
        // 进程内存表对应的进程number
        'table_process_pools_number' => [
            // 内存表建立的行数,取决于建立的process进程数
            'size' => 256,
            // 字段
            'fields'=> [
                ['pnumber','int', 5]
            ]
        ],
	];

	private static $processList = [];

    private static $processNameList = [];

    public static $process_used = [];

    /**
     * __construct
     * @param  $total_process 进程池总数
     */
	public function __construct(int $total_process = 100) {
        self::$table_process['table_process_pools_map']['size'] = self::$table_process['table_process_pools_number']['size']= $total_process;
		TableManager::getInstance()->createTable(self::$table_process);
	}

	/**
	 * addProcess 添加一个进程
	 * @param string  $processName
	 * @param string  $processClass
     * @param int     $processNumber
	 * @param boolean $async
	 * @param array   $args
	 */
	public static function addProcessPools(string $processName, string $processClass, int $processNumber = 1, $async = true, array $args = []) {
		if(!TableManager::isExistTable('table_process_pools_map')) {
			TableManager::getInstance()->createTable(self::$table_process);
		}

        if(!empty(self::$processList)) {
            // 剩余可创建进程数
            $count = count(self::$processList);
            $left_process_num = self::$table_process['table_process_pools_map']['size'] - $count;
            if($left_process_num <= 0) {
                throw new \Exception("You have created total process number $count", 1);   
            }
        }else {
            // 可创建的进程数
            $total_process_num = self::$table_process['table_process_pools_map']['size'];
            if($processNumber > $total_process_num) {
                throw new \Exception("You only created process number $total_process_num", 1);
            }
        }

		for($i=1; $i<=$processNumber; $i++) {
            $process_name = $processName.$i;
			$key = md5($process_name);
	        if(!isset(self::$processList[$key])){
	            try{
	                $process = new $processClass($process_name, $async, $args, $i);
	                self::$processList[$key] = $process;
                    self::$processNameList[$processName][] = $process_name;
	            }catch (\Exception $e){
	                throw new \Exception($e->getMessage(), 1);       
	            }
	        }else{
	            throw new \Exception("you can not add the same process : $process_name", 1);
	            return false;
	        }
        }

        // self::registerProcessFinish(self::$processList);

        Process::signal(SIGCHLD, function($signo) use($processName, $processClass, $async, $args) {
	        while($ret = Process::wait(false)) { 
	            if($ret) {
	            	$pid = $ret['pid'];
	            	$process_num = TableManager::getInstance()->getTable('table_process_pools_number')->get($pid, 'pnumber');
                    $process_name = $processName.$process_num;
	                $key = md5($process_name);
                    unset(self::$processList[$key]);
                    TableManager::getInstance()->getTable('table_process_pools_number')->del($pid);
                    try{
                        $process = new $processClass($process_name, $async, $args, $process_num);
                        self::$processList[$key] = $process;
                        // self::registerProcessFinish([$process]);
                    }catch (\Exception $e){
                        throw new \Exception($e->getMessage(), 1);       
                    }
	           }
            }
	    });
	}

    /**
     * registerProcessFinish
     * @return 
     */
    public static function registerProcessFinish(array $processList = []) {
        foreach ($processList as $process_class) {
            $process = $process_class->getProcess();
            swoole_event_add($process->pipe, function ($pipe) use($process) {
                $pid = $process->read();
                self::$process_used[$pid] = 0;
            });
        }
    }

	/**
	 * getProcessByName 通过名称获取一个进程
	 * @param  string $processName
     * @param  int    $process_num
	 * @return object
	 */
	public static function getProcessByName(string $processName, $process_num = null) {
		$processName = $processName.$process_num;
        $key = md5($processName);
        if(isset(self::$processList[$key])){
            return self::$processList[$key];
        }else{
            return null;
        }
    }

    /**
     * getProcessByPid 通过进程id获取进程
     * @param  int    $pid
     * @return object
     */
    public static function getProcessByPid(int $pid) {
        $table = TableManager::getTable('table_process_pools_map');
        foreach ($table as $key => $item){
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
    public static function setProcess(string $processName, AbstractProcessPools $process) {
        $key = md5($processName);
        self::$processList[$key] = $process;
    }

    /**
     * reboot 重启某个进程
     * @param  string $processName
     * @param  int    $process_num
     * @return boolean
     */
    public static function reboot(string $processName, $process_num = null) {
    	$processName = $processName.$process_num;
        $process = self::getProcessByName($processName)->getProcess();
        $pid = $process->pid;
        if($pid){
            $process->kill($pid, SIGTERM);
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
    public static function writeByProcessName(string $name, string $data) {
        $process = self::getProcessByName($name);
        if($process){
            return (bool)$process->getProcess()->write($data);
        }else{
            return false;
        }
    }

    /**
     * writeByRandom 
     * @param  string $name
     * @param  string $data
     * @return 
     */
    public static function writeByRandom(string $name, string $data) {
        if(self::$processNameList[$name]) {
            $key = array_rand(self::$processNameList[$name], 1);
            $process_name = self::$processNameList[$name][$key];
        }
        self::writeByProcessName($process_name, $data);
    }

    /**
     * readByProcessName 读取某个进程数据
     * @param  string $name
     * @param  float  $timeOut
     * @return mixed
     */
    public static function readByProcessName(string $name, float $timeOut = 0.1) {
        $process = self::getProcessByName($name);
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