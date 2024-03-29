<?php
/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | @see https://github.com/bingcool/swoolefy
 * +----------------------------------------------------------------------
 */

namespace Swoolefy\Core\ProcessPools;

use Swoole\Process;
use Swoolefy\Core\Swfy;
use Swoolefy\Core\Table\TableManager;
use Swoolefy\Exception\SystemException;

class PoolsManager
{

    use \Swoolefy\Core\SingletonTrait;

    /**
     * @var int
     */
    const PROCESS_NUM = 1024;

    /**
     * @var array
     */
    private $tableProcess = [
        // 进程内存表
        'table_process_pools_map' => [
            // 内存表建立的行数,取决于建立的process进程数,最小值64
            'size' => self::PROCESS_NUM,
            // 字段
            'fields' => [
                //从4.3版本开始，底层对内存长度做了对齐处理。字符串长度必须为8的整数倍。如长度为18会自动对齐到24字节
                ['pid', 'int', 10],
                ['process_name', 'string', 56],
            ]
        ]
    ];

    /**
     * @var int
     */
    private $totalProcessNum = 0;

    /**
     * @var array
     */
    private $processList = [];

    /**
     * @var int
     */
    private $workerNum = 1;

    /**
     * @var array
     */
    private $processListInfo = [];

    /**
     * __construct
     * 每个worker绑定的进程数，也即是为每个worker附加的自定义进程数，默认绑定一个process
     */
    protected function __construct()
    {
        $conf = Swfy::getConf();
        if (isset($conf['setting']['worker_num'])) {
            $this->workerNum = $conf['setting']['worker_num'];
        }
        TableManager::getInstance()->createTable($this->tableProcess);
    }

    /**
     * addProcess 添加创建进程并绑定当前worker进程
     * @param string $processName
     * @param string $processClass
     * @param int $processNumBindWorker 每个worker绑定的进程数，也即是为每个worker附加的自定义进程数，默认绑定一个process
     * @param bool $async
     * @param array $args
     * @param mixed $extendData
     * @param bool $enableCoroutine
     * @return void
     * @throws SystemException
     */
    public function addProcessPools(
        string $processName,
        string $processClass,
        int    $processNumBindWorker = 1,
        bool   $async = true,
        array  $args = [],
               $extendData = null,
        bool   $enableCoroutine = true
    )
    {
        if (!TableManager::isExistTable('table_process_pools_map')) {
            TableManager::getInstance()->createTable($this->tableProcess);
        }
        // total process num
        $this->totalProcessNum += ($this->workerNum * $processNumBindWorker);

        if ($this->totalProcessNum > self::PROCESS_NUM) {
            throw new SystemException("PoolsManager Error : total user process num more then " . self::PROCESS_NUM);
        }

        $key = md5($processName);
        if (isset($this->processList[$key])) {
            throw new SystemException("PoolsManager Error : you can not add the same process : $processName");
        }

        for ($i = 0; $i < $this->workerNum; $i++) {
            for ($j = 0; $j < $processNumBindWorker; $j++) {
                try {
                    /**@var AbstractProcessPools $process */
                    $process = new $processClass($processName . '@' . $i . '@' . $j, $async, $args, $extendData, $enableCoroutine);
                    $process->setBindWorkerId($i);
                    $this->processList[$key][$i][$j] = $process;
                    $this->processListInfo[$processName] = ['process_name' => $processName . '@' . $i . '@' . $j, 'bind_worker_num' => $i, 'process_worker_num' => $j, 'class' => $processClass];
                } catch (\Throwable $exception) {
                    throw $exception;
                }
            }
        }
    }

    /**
     * @return array
     */
    public function getProcessListInfo()
    {
        return $this->processListInfo;
    }

    /**
     * getProcessByName 通过名称获取绑定当前worker进程的某个进程
     * @param string $processName
     * @param bool $isReturnAll 是否返回worker中绑定的所有process
     * @return AbstractProcessPools|array
     * @throws SystemException
     */
    public function getProcessPoolsByName(string $processName, bool $isReturnAll = false)
    {
        if (!Swfy::isWorkerProcess()) {
            throw new SystemException("PoolsManager::getInstance() can not use in task or self process, only use in worker process");
        }
        $workerId = Swfy::getCurrentWorkerId();
        $key = md5($processName);
        if (isset($this->processList[$key][$workerId])) {
            if ($isReturnAll) {
                return $this->processList[$key][$workerId];
            }
            // 随机返回当前worker进程绑定的一个附属进程
            $k = array_rand($this->processList[$key][$workerId]);
            return $this->processList[$key][$workerId][$k];
        }
        return null;
    }

    /**
     * getProcessByPid 通过进程id获取绑定当前worker进程的某个进程
     * @param int $pid
     * @return mixed
     * @throws SystemException
     */
    public function getProcessPoolsByPid(int $pid)
    {
        if (!Swfy::isWorkerProcess()) {
            throw new SystemException("PoolsManager::getInstance() can not use in task or self process, only use in worker process");
        }

        $table = TableManager::getTable('table_process_pools_map');
        foreach ($table as $key => $item) {
            if ($item['pid'] == $pid) {
                list($processName, $workerId, $processNum) = explode('@', $item['process_name']);
                $key = md5($processName);
                return $this->processList[$key][$workerId][$processNum];
            }
        }

        return null;
    }

    /**
     * setProcess 绑定当前worker进程的设置一个进程
     * @param string $processName
     * @param AbstractProcessPools $process
     */
    public function setProcessPools(string $processName, AbstractProcessPools $process)
    {
        $workerId = Swfy::getCurrentWorkerId();
        $key = md5($processName);
        array_push($this->processList[$key][$workerId], $process);
    }

    /**
     * reboot 重启进程
     * @param string $processName
     * @param bool $isRestartFullProcess
     * @return bool
     */
    public function rebootPools(string $processName, bool $isRestartFullProcess = false)
    {
        if ($isRestartFullProcess) {
            foreach ($this->workerNum as $workerProcesses) {
                foreach ($workerProcesses as $processList) {
                    /**@var AbstractProcessPools $process */
                    foreach ($processList as $process) {
                        $killFlag = $process->getSwoolefyProcessKillFlag();
                        $process->getProcess()->write($killFlag);
                    }
                }
            }
            return true;
        }

        $processList = $this->getProcessPoolsByName($processName, true);
        if (is_array($processList) && count($processList) > 0) {
            foreach ($processList as $process) {
                /**@var AbstractProcessPools $process */
                $killFlag = $process->getSwoolefyProcessKillFlag();
                $process->getProcess()->write($killFlag);
            }
        }
        return true;
    }

    /**
     * writeByProcessName 向绑定当前worker进程的某个自定义进程写数据
     * 如果需要获取等待的结果，可以设置callback,在规定时间内读取返回数据回调处理
     * @param string $name
     * @param mixed $data
     * @param \Closure $callback
     * @param float $timeOut
     * @return Process
     */
    public function writeByProcessPoolsName(string $processName, $data, \Closure $callback = null, float $timeOut = 3.0 )
    {
        $process = $this->getProcessPoolsByName($processName);
        if ($process) {
            if (is_array($data)) {
                $data = json_encode($data, JSON_UNESCAPED_UNICODE);
            }
            $result = (bool)$process->getProcess()->write($data);
            if ($result && $callback instanceof \Closure) {
                $msg = $this->read($process->getProcess(), $timeOut);
                call_user_func($callback, $msg ?? null);
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
    public function read(Process $swooleProcess, float $timeOut = 3.0 )
    {
        $result = null;
        $read = [$swooleProcess];
        $write = [];
        $except = [];
        if (function_exists('swoole_client_select')) {
            $ret = swoole_client_select($read, $write, $except, $timeOut);
        } else {
            if ($timeOut < 1) {
                $timeOut = 1;
            }
            $timeOut = (int)$timeOut;
            $ret = stream_select($read, $write, $except, $timeOut);
        }

        if ($ret) {
            $result = $swooleProcess->read(64 * 1024);
        }

        return $result;
    }
}