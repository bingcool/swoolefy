<?php 
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
			'size' => 100,
			// 字段
			'fields'=> [
				['pid','int', 10]
			]
		],
	];

	private static $processList = [];

	private static $pidMapKey = [];

    /**
     * __construct
     * @param  $total_process 进程池总数
     */
	public function __construct(int $total_process = 256) {
        self::$table_process['table_process_pools_map']['size'] = $total_process;
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
            $processName = $processName.$i;
			$key = md5($processName);
	        if(!isset(self::$processList[$key])){
	            try{
	                $process = new $processClass($processName, $async, $args);
	                $pid = $process->getPid();
	                self::$pidMapKey[$pid] = $i;
	                self::$processList[$key] = $process;
	            }catch (\Exception $e){
	                throw new \Exception($e->getMessage(), 1);       
	            }
	        }else{
	            throw new \Exception("you can not add the same process : $processName", 1);
	            return false;
	        }
        }

        Process::signal(SIGCHLD, function($signo) use($processName, $processClass, $async, $args) {
	        while(1)
	        {
	            $ret = Process::wait(false);
	            if($ret) {
	            	$pid = $ret['pid'];
	            	$process_num = self::$pidMapKey[$pid];
                    $processName = $processName.$process_num;
	                $key = md5($processName);
                    unset(self::$processList[$key], self::$pidMapKey[$pid]);
                    try{
                        $process = new $processClass($processName, $async, $args);
                        $pid = $process->getPid();
                        self::$pidMapKey[$pid] = $i;
                        self::$processList[$key] = $process;
                    }catch (\Exception $e){
                        throw new \Exception($e->getMessage(), 1);       
                    }
	            }else {
	                break;
	            }
	        }
	    });
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
        $p = self::getProcessByName($processName);
        if($p){
            \swoole_process::kill($p->getPid(), SIGTERM);
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