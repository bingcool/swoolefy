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

namespace Swoolefy\Core\Process;

use Swoole\Process;
use Swoole\Table;
use Swoolefy\Core\Table\TableManager;

class ProcessManager
{

    use \Swoolefy\Core\SingletonTrait;

    /**
     * const
     */
    const PROCESS_NUM = 1024;

    /**
     * @var array
     */
    private $tableProcess = [
        // 进程内存表
        'table_process_map' => [
            // 内存表建立的行数,取决于建立的process进程数，默认最小值64
            'size' => self::PROCESS_NUM,
            // 字段
            'fields' => [
                ['pid', 'int', 10]
            ]
        ],
    ];

    /**
     * @var array
     */
    private $processList = [];

    /**
     * @var array
     */
    private $processListInfo = [];

    /**
     * __construct
     */
    private function __construct()
    {
        TableManager::getInstance()->createTable($this->tableProcess);
    }

    /**
     * addProcess
     *
     * @param string $processName
     * @param string $processClass
     * @param bool $async
     * @param array $args
     * @param mixed $extendData
     * @param bool $enableCoroutine
     * @return mixed
     * @throws mixed
     */
    public function addProcess(
        string $processName,
        string $processClass,
        bool   $async = true,
        array  $args = [],
        ?array $extendData = null,
        bool   $enableCoroutine = true
    )
    {
        $key = md5($processName);
        if (isset($this->processList[$key])) {
            throw new \Exception("You can not add the same process : $processName");
        }

        if (!TableManager::isExistTable('table_process_map')) {
            TableManager::getInstance()->createTable($this->tableProcess);
        }

        try {
            /**@var AbstractProcess $process */
            $process = new $processClass(
                $processName,
                $async,
                $args,
                $extendData,
                $enableCoroutine
            );
            $this->processList[$key] = $process;
            $this->processListInfo[$processName] = ['process_name' => $processName, 'class' => $processClass];
            return $process;
        } catch (\Exception $exception) {
            throw $exception;
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
     * getProcessByName 通过名称获取一个进程
     * @param string $processName
     * @return AbstractProcess
     */
    public function getProcessByName(string $processName)
    {
        $key = md5($processName);
        return $this->processList[$key] ?? null;
    }

    /**
     * getProcessByPid 通过进程id获取进程
     * @param int $pid
     * @return mixed
     */
    public function getProcessByPid(int $pid)
    {
        $table = TableManager::getTable('table_process_map');
        foreach ($table as $key => $item) {
            if ($item['pid'] == $pid) {
                return $this->processList[$key];
            }
        }
        return null;
    }

    /**
     * setProcess 设置一个进程
     * @param string $processName
     * @param AbstractProcess $process
     * @return void
     */
    public function setProcess(string $processName, AbstractProcess $process)
    {
        $key = md5($processName);
        $this->processList[$key] = $process;
    }

    /**
     * reboot 重启某个进程
     * @param string $processName
     * @return bool
     */
    public function reboot(string $processName)
    {
        $process = $this->getProcessByName($processName);
        $killFlag = $process->getSwoolefyProcessKillFlag();
        $this->writeByProcessName($processName, $killFlag);
        return true;
    }

    /**
     * writeByProcessName 向绑定当前worker进程的某个自定义进程写数据
     * 如果需要获取等待的结果，可以设置callback,在规定时间内读取返回数据回调处理
     *
     * @param string $name
     * @param mixed $data
     * @param \Closure|null $callback
     * @param float $timeOut
     * @return bool
     */
    public function writeByProcessName(string $name, $data, \Closure $callback = null, float $timeOut = 3.0)
    {
        $process = $this->getProcessByName($name);
        if ($process) {
            if (is_array($data)) {
                $data = json_encode($data, JSON_UNESCAPED_UNICODE);
            }
            $result = (bool)$process->getProcess()->write($data);
            if ($result && $callback instanceof \Closure) {
                $msg = $this->read($process->getProcess(), $timeOut);
                call_user_func($callback, $msg ?? null);
            }
            return true;
        }
        return false;
    }

    /**
     * readByProcessName 读取某个进程数据
     * @param string $name
     * @param float $timeOut
     * @return mixed
     */
    public function readByProcessName(string $name, float $timeOut = 3)
    {
        $process = $this->getProcessByName($name);
        if ($process) {
            $swooleProcess = $process->getProcess();
            return $this->read($swooleProcess, $timeOut);
        }
        return null;
    }

    /**
     * read 在规定时间内读取数据
     * @param Process $swooleProcess
     * @param float $timeOut
     * @return mixed
     */
    public function read(Process $swooleProcess, float $timeOut = 3)
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