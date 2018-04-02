<?php
namespace Swoolefy\Core\Process;
/**
 * 本进程模块参考easyswooole的process
 * @see  https://github.com/easy-swoole/easyswoole/tree/2.x/src/Core/Swoole/Process
 */

use Swoole\Process;
use Swoole\Table;
use Swoolefy\Core\Table\TableManager;

class ProcessManager {

    use \Swoolefy\Core\SingleTrait;
	
	private $table_process = [
		// 进程内存表
		'table_process_map' => [
			// 内存表建立的行数,取决于建立的process进程数
			'size' => 256,
			// 字段
			'fields'=> [
				['pid','int', 10]
			]
		],
	];

	private $processList = [];

    /**
     * __construct 
     */
	public function __construct() {
		TableManager::getInstance()->createTable($this->table_process);
	}

	/**
	 * addProcess 添加一个进程
	 * @param string  $processName
	 * @param string  $processClass
	 * @param boolean $async
	 * @param array   $args
	 */
	public function addProcess(string $processName, string $processClass, $async = true,array $args = []) {
		if(!TableManager::isExistTable('table_process_map')) {
			TableManager::getInstance()->createTable($this->table_process);
		}

		$key = md5($processName);
        if(!isset($this->processList[$key])){
            try{
                $process = new $processClass($processName, $async, $args);
                $this->processList[$key] = $process;
                return true;
            }catch (\Exception $e){
                throw new \Exception($e->getMessage(), 1);       
            }
        }else{
            throw new \Exception("you can not add the same process : $processName", 1);
            return false;
        }
	}

	/**
	 * getProcessByName 通过名称获取一个进程
	 * @param  string $processName
	 * @return object
	 */
	public function getProcessByName(string $processName) {
        $key = md5($processName);
        if(isset($this->processList[$key])){
            return $this->processList[$key];
        }else{
            return null;
        }
    }

    /**
     * getProcessByPid 通过进程id获取进程
     * @param  int    $pid
     * @return object
     */
    public function getProcessByPid(int $pid) {
        $table = TableManager::getTable('table_process_map');
        foreach ($table as $key => $item){
            if($item['pid'] == $pid){
                return $this->processList[$key];
            }
        }
        return null;
    }

    /**
     * setProcess 设置一个进程
     * @param string          $processName
     * @param AbstractProcess $process
     */
    public function setProcess(string $processName, AbstractProcess $process) {
        $key = md5($processName);
        $this->processList[$key] = $process;
    }

    /**
     * reboot 重启某个进程
     * @param  string $processName
     * @return boolean
     */
    public function reboot(string $processName) {
        $p = $this->getProcessByName($processName);
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
    public function writeByProcessName(string $name,string $data) {
        $process = $this->getProcessByName($name);
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
    public function readByProcessName(string $name, float $timeOut = 0.1) {
        $process = $this->getProcessByName($name);
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