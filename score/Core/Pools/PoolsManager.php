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
		],
        // 进程内存表对应的进程number
        'table_process_pools_number' => [
            // 内存表建立的行数,取决于建立的process进程数
            'size' => 256,
            // 字段
            'fields'=> [
                ['pnumber','int', 5],
                ['masterProcessName','string', 20]
            ]
        ],
	];

	private static $processList = [];

    private static $processNameList = [];

    private static $process_free = [];

    private static $process_name_list = [];

    private static $channels = [];

    private static $timer_ids = [];

    private static $process_args = [];

    /**
     * __construct
     * @param  $total_process 进程池总数
     */
	public function __construct(int $total_process = 256) {
        self::$table_process['table_process_pools_map']['size'] = self::$table_process['table_process_pools_number']['size']= $total_process;
		TableManager::getInstance()->createTable(self::$table_process);
	}

	/**
	 * addProcess 添加创建进程
	 * @param string  $processName
	 * @param string  $processClass
     * @param int     $processNumber
	 * @param boolean $async
     * @param boolean $polling 是否是轮训向空闲进程写数据
	 * @param array   $args
	 */
	public static function addProcessPools(string $processName, string $processClass, int $processNumber = 1, $polling = false, int $timer_int= 50, $async = true, array $args = []) {
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

        $worker_id = Swfy::getCurrentWorkerId();

		for($i=1; $i<=$processNumber; $i++) {
            $process_name = $processName.$i;
			$key = md5($process_name);
            $args = [$i, $worker_id, $polling, $processName];
	        if(!isset(self::$processList[$key])){
	            try{
	                $process = new $processClass($process_name, $async, $args);
	                self::$processList[$key] = $process;
                    self::$process_name_list[$processName][$key] = $process;
                    self::$processNameList[$processName][] = $process_name;
	            }catch (\Exception $e){
	                throw new \Exception($e->getMessage(), 1);       
	            }
	        }else{
	            throw new \Exception("you can not add the same process : $process_name", 1);
	            return false;
	        }
        }

        if($polling) {
            self::registerProcessFinish(self::$process_name_list[$processName], $processName);
            self::$channels[$processName] = new \Swoole\Channel(2 * 1024 * 2014);
            self::loopWrite($processName, $timer_int);
        }

        self::$process_args[$processName] = [$processClass, $async, $worker_id, $polling];

        Process::signal(SIGCHLD, function($signo) {
	        while($ret = Process::wait(false)) { 
	            if($ret) {
	            	$pid = $ret['pid'];
	            	$processInfo = TableManager::getInstance()->getTable('table_process_pools_number')->get($pid);
                    list($process_num, $processName) = array_values($processInfo);
                    list($processClass, $async, $worker_id, $polling) = self::$process_args[$processName];
                    $process_name = $processName.$process_num;
	                $key = md5($process_name);
                    $polling && swoole_event_del(self::$processList[$key]->getProcess()->pipe);
                    unset(self::$processList[$key], self::$process_name_list[$processName][$key]);
                    TableManager::getInstance()->getTable('table_process_pools_number')->del($pid);
                    $args = [$process_num, $worker_id, $polling, $processName];
                    try{
                        $process = new $processClass($process_name, $async, $args);
                        self::$processList[$key] = $process;
                        self::$process_name_list[$processName][$key] = $process;
                        $polling && self::registerProcessFinish([$process], $processName);
                        unset($args, $process_num, $worker_id, $polling, $processName);
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
    public static function registerProcessFinish(array $processList = [], string $processName) {
        foreach($processList as $process_class) {
            $process = $process_class->getProcess();
            $processname = $process_class->getProcessName();
            // 默认所有进程空闲
            self::$process_free[$processName][$processname] = 0;
            swoole_event_add($process->pipe, function ($pipe) use($process, $processName) {
                $process_name = $process->read(64 * 1024);
                // 属于$processName主进程的子进程
                if(in_array($process_name, self::$processNameList[$processName])) {
                    // 子进程大于0，说明该子进程收到重启的命令，那么将在任务完成后重启
                    if(isset(self::$process_free[$processName][$process_name]) && $pid = self::$process_free[$processName][$process_name] > 0) {
                        $process->kill($pid, SIGTERM);
                        unset(self::$process_free[$processName][$process_name]);
                    }else {
                        // 正常设置子进程为空闲
                        self::$process_free[$processName][$process_name] = 0;
                    }
                    
                }
            });
        }
    }

    /**
     * loopWrite 定时循环向子进程写数据
     * @return   mixed
     */
    public static function loopWrite(string $processName, $timer_int) {
        $timer_id = swoole_timer_tick($timer_int, function($timer_id) use($processName) {
            if(count(self::$process_free[$processName])) {
                $channel= self::$channels[$processName];
                $data = $channel->pop();
                if($data) {
                   // 轮询空闲进程
                    foreach(self::$process_free[$processName] as $process_name=>$value) {
                        if($value === 0) {
                            self::writeByProcessName($process_name, $data);
                            unset(self::$process_free[$processName][$process_name]);
                            break;
                        }
                    }   
                }
            }
        });
        self::$timer_ids[$processName] = $timer_id;
        return $timer_id;
    }

    /**
     * getTimerId 获取当前的定时器id
     * @param   string  $processName
     * @return  mixed
     */
    public static function getTimerId(string $processName) {
        if(isset(self::$timer_ids[$processName]) && self::$timer_ids[$processName]!== null) {
            return self::$timer_ids[$processName];
        }
        return null;
    }

    /**
     * clearTimer 清除进程内的定时器
     * @param    string  $processName
     * @return   boolean
     */
    public static function clearTimer(string $processName) {
        $timer_id = self::getTimerId($processName);
        if($timer_id) {
            return swoole_timer_clear($timer_id);  
        }
        return false;
    }

    /**
     * getChannel
     * @param    string   $processName
     * @return   object
     */
    public static function getChannel(string $processName) {
        if(is_object(self::$channels[$processName])) {
            return self::$channels[$processName];
        }
        return null;
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
    	$process_name = $processName.$process_num;
        $process = self::getProcessByName($process_name)->getProcess();
        $pid = $process->pid;
        if($pid){
            $processInfo = TableManager::getInstance()->getTable('table_process_pools_number')->get($pid);
            list($process_num, $processName) = array_values($processInfo);
            // 异步轮训方式
            if(isset(self::$process_free[$processName])) {
                // 当前子进程空闲，则可以立即重启
                if(in_array($process_name, self::$process_free[$processName]) && self::$process_free[$processName][$process_name] === 0) {
                    $process->kill($pid, SIGTERM);
                }else {
                    // 当前子进程正在作业忙碌的,设置成$pid，代表子进程在完成任务后，将自动重启
                    // 避免重复接受多次重启命令
                    if(!isset(self::$process_free[$processName][$process_name])) {
                        self::$process_free[$processName][$process_name] = $pid;
                    }
                }
            }else {
                // 其他模式直接重启子进程
                $process->kill($pid, SIGTERM);
            }
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
     * writeByRandom 任意方式向进程写数据
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
        return $process_name;
    }

    /**
     * writeByPolling 轮训方式向空闲进程写数据 
     * @param  string $name
     * @param  string $data
     * @return 
     */
    public static function writeByPolling(string $name, string $data) {
        $channel = self::$channels[$name];
        return $channel->push($data);
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