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

namespace Swoolefy\Worker\Cron;

use Swoole\Coroutine\Channel;
use Swoole\Coroutine\System;
use Swoole\Process;
use Swoolefy\Core\Exec;
use Swoolefy\Core\Dto\RunProcessMetaDto;
use Swoolefy\Exception\SystemException;
use Swoolefy\Script\AbstractKernel;

class CronForkRunner
{
    /**
     * @var array
     */
    protected static $instances = [];

    /**
     * @var Channel
     */
    protected static $checkRunningTickerChannel;

    /**
     * @var Channel
     */
    protected static $deleteExistTickerChannel;

    /**
     * @var Channel
     */
    protected $channel;

    /**
     * @var int
     */
    protected $concurrent = 5;

    /**
     * @var int
     */
    protected $checkTickerTime = 10;

    /**
     * @var int
     */
    protected $deleteExistTickerTime= 120;

    /**
     * @var bool
     */
    protected $isNextFlag = false;

    /**
     * @var array
     */
    public static $exitCodes = Exec::EXIT_CODES;

    /**
     * @param string $runnerName
     * @param int $concurrent
     * @return CronForkRunner
     */
    public static function getInstance(string $runnerName, int $concurrent = 5)
    {
        if (!isset(static::$instances[$runnerName])) {
            /**@var CronForkRunner $runner */
            $runner = new static();
            if ($concurrent >= 10) {
                $concurrent = 10;
            }
            $runner->concurrent = $concurrent;
            if (\Swoole\Coroutine::getCid() >= 0) {
                $runner->channel = new Channel($concurrent);
            }
            static::$instances[$runnerName] = $runner;
        }

        if (is_null(static::$checkRunningTickerChannel)) {
            $runner->registerTickOfCheckRunningProcess();
        }

        if (is_null(static::$deleteExistTickerChannel)) {
            $runner->registerTickOfDeleteExistPidFile();
        }

        return static::$instances[$runnerName];
    }

    /**
     * 执行外部系统程序，包括php,shell so on
     * 禁止swoole提供的process->exec，因为swoole的process->exec调用的程序会替换当前子进程
     * @param string $execBinFile
     * @param string $execScript
     * @param array $args
     * @param bool $async
     * @param string $log
     * @param bool $isExec
     * @param array $extend
     * @return array
     * @throws SystemException
     */
    public function exec(
        string $execBinFile,
        string $execScript,
        array  $args = [],
        bool   $async = false,
        string $log = '/dev/null',
        bool   $isExec = true,
        array  $extend = []
    )
    {
        $this->checkNextFlag();
        $argvOption = '';
        if ($args) {
            $argvOption = $this->parseEscapeShellArg($args);
        }

        $path = $execBinFile . ' ' . $execScript . ' ' . $argvOption;
        $path = trim($path,' ');
        if ($log) {
            $command = "{$path} >> {$log} 2>&1; echo $$";
        }else {
            $command = "{$path} 2>&1; echo $$";
        }

        if ($async) {
            // echo $! 表示输出进程id赋值在output数组中
            if ($log) {
                $command = "nohup {$path} >> {$log} 2>&1; echo $!";
            }else {
                $command = "nohup {$path} 2>&1; echo $!";
            }
        }

        if ($isExec) {
            $exec       = (new Exec())->run($command);
            $output     = $exec->getOutput();
            $returnCode = $exec->getReturnCode();
            $pid = $output[0] ?? '';
            if (!$this->channel instanceof Channel) {
                $this->channel = new Channel($this->concurrent);
            }

            if ($pid) {
                $runProcessMetaDto = new RunProcessMetaDto();
                $runProcessMetaDto->pid = (int)trim($pid);
                $runProcessMetaDto->command = $command;
                $runProcessMetaDto->pid_file = '';
                $runProcessMetaDto->check_total_count = 0;
                $runProcessMetaDto->check_pid_not_exist_count = 0;
                $runProcessMetaDto->start_timestamp = time();
                $runProcessMetaDto->start_date_time = date('Y-m-d H:i:s');

                $cronScriptPidFileOption = AbstractKernel::getCronScriptPidFileOptionField();
                if (isset($extend[$cronScriptPidFileOption])) {
                    $cronScriptPidFile = $extend[$cronScriptPidFileOption];
                    if (is_file($cronScriptPidFile)) {
                        $runProcessMetaDto->pid = (int)trim(file_get_contents($cronScriptPidFile));
                    }else {
                        $runProcessMetaDto->pid = 0;
                    }
                    $runProcessMetaDto->pid_file = $cronScriptPidFile;
                    $this->debug("拉起新的进程pid_file:".$runProcessMetaDto->pid_file);
                }else {
                    $this->debug("拉起新的进程pid:".$runProcessMetaDto->pid);
                }
                $this->channel->push($runProcessMetaDto, 0.2);
            }
            // when exec error save log
            if ($returnCode != 0) {
                $errorMsg = static::$exitCodes[$returnCode] ?? 'Unknown Error';
                throw new SystemException("CommandRunner Exec failed,return code ={$returnCode},commandLine={$command},errorMsg={$errorMsg}.");
            }
        }

        return [$command, $output ?? [], $returnCode ?? -1];
    }

    /**
     * @param string $execBinFile
     * @param string $execScript
     * @param array $args
     * @param callable $callable
     * @param array $extend
     * @return void
     * @throws SystemException
     */
    public function procOpen(
        string   $execBinFile,
        string   $execScript,
        array    $args = [],
        ?callable $callable = null,
        array    $extend = []
    )
    {
        $this->checkNextFlag();
        $argvOption = '';
        if ($args) {
            $argvOption = $this->parseEscapeShellArg($args);
        }

        $command = $execBinFile .' '.$execScript.' ' . $argvOption . '; echo $? >&3; echo $! >&4';

        $command      = trim($command);
        $descriptors = array(
            // stdout
            0 => array('pipe', 'r'),
            // stdin
            1 => array('pipe', 'w'),
            // stderr
            2 => array('pipe', 'w'),
            // return exist code
            3 => array('pipe', 'w'),
            // return process pid
            4 => array('pipe', 'w'),
        );

        $fn = function ($command, $descriptors, $callable) use($extend) {
            // in $callable forbidden create coroutine, because $proc_process had been bind in current coroutine
            try {
                $proc_process = proc_open($command, $descriptors, $pipes);
                if (!is_resource($proc_process)) {
                    throw new SystemException("Proc Open Command 【{$command}】 failed.");
                }
                $status = proc_get_status($proc_process);
                $status['pid'] = (int)trim(fgets($pipes[4], 10));

                $cronScriptPidFileOption = AbstractKernel::getCronScriptPidFileOptionField();

                $runProcessMetaDto = new RunProcessMetaDto();
                $runProcessMetaDto->pid = $status['pid'] ?? 0;
                $runProcessMetaDto->command = $command;
                $runProcessMetaDto->pid_file = '';
                $runProcessMetaDto->check_total_count = 0;
                $runProcessMetaDto->check_pid_not_exist_count = 0;
                $runProcessMetaDto->start_timestamp = time();
                $runProcessMetaDto->start_date_time = date('Y-m-d H:i:s');

                if (isset($extend[$cronScriptPidFileOption])) {
                    $cronScriptPidFile = $extend[$cronScriptPidFileOption];
                    if (is_file($cronScriptPidFile)) {
                        $runProcessMetaDto->pid = (int)trim(file_get_contents($cronScriptPidFile));
                    }else {
                        $runProcessMetaDto->pid = 0;
                    }
                    $runProcessMetaDto->pid_file = $cronScriptPidFile;
                    $this->debug("拉起新的进程pid_file:".$runProcessMetaDto->pid_file);
                }else {
                    $this->debug("拉起新的进程pid:".$runProcessMetaDto->pid);
                }

                // 协程环境设置channel控制并发数，isNextHandle()函数判断是否可以并发拉起下一个进程
                if (\Swoole\Coroutine::getCid() >= 0) {
                    if (!$this->channel instanceof Channel) {
                        $this->channel = new Channel($this->concurrent);
                    }
                    $this->channel->push($runProcessMetaDto, 0.2);
                    $this->debug("after push channel length=".$this->channel->length());
                }else {
                    if (!Process::kill($status['pid'], 0)) {
                        $returnCode = fgets($pipes[3], 10);
                        $errorMsg   = static::$exitCodes[$returnCode] ?? 'Unknown Error';
                        throw new SystemException("CommandRunner Proc Open failed,return Code={$returnCode},commandLine={$command}, errorMsg={$errorMsg}.");
                    }
                }
                $statusProperty = $runProcessMetaDto->toArray();
                $params = [$pipes[0], $pipes[1], $pipes[2], $statusProperty];
                call_user_func_array($callable, $params);
                return $statusProperty;
            } catch (\Throwable $e) {
                fmtPrintError("CommandRunner ErrorMsg={$e->getMessage()},trace={$e->getTraceAsString()}");
            } finally {
                foreach ($pipes as $pipe) {
                    @fclose($pipe);
                }
                proc_close($proc_process);
            }
            return null;
        };

        if (!is_callable($callable)) {
            $callable = function ($pipe0, $pipe1, $pipe2, $statusProperty) {

            };
        }

        if (\Swoole\Coroutine::getCid() >= 0) {
            goApp(function () use ($fn, $callable, $command, $descriptors) {
                $fn($command, $descriptors, $callable);
            });
        }else {
            $fn($command, $descriptors, $callable);
        }
    }

    /**
     * @param bool $isNeedCheck 是否需要控制并发拉起进程，在while循环中，一般需要调用该函数来控制并发拉起进程。不控制的话，将瞬间拉起大量进程，导致系统崩溃.eg:
     *
     * $runner = CommandRunner::getInstance("test-runner", 5)
     * \Swoole\timer::tick(230 * 1000, function(){
     *    // 是否满足拉起下一个进程.这个函数主要是判断拉起的进程数是否已达到最大并发数.
     *    if ($runner->isNextHandle(true, 60)) {
     *         // todo
     *         $runner->procOpen('/bin/sh','ls -l',[])
     *     }
     * })
     * @param int $timeOut 规定时间内达到，强制拉起下一个进程
     * @return bool
     */
    public function isNextHandle(bool $isNeedCheck = true, int $timeOut = 60)
    {
        $this->isNextFlag = true;
        $this->gcExitProcess();
        if ($this->channel instanceof Channel && $this->channel->length() >= $this->concurrent && $isNeedCheck) {
            $itemList = [];
            /**
             * @var RunProcessMetaDto $runProcessMetaItem
             */
            while ($runProcessMetaItem = $this->channel->pop(0.02)) {
                $startTime  = $runProcessMetaItem->start_timestamp;
                $itemList[] = $runProcessMetaItem;
                // 进程已经存在，并且已经执行超过了规定时间,强制拉起下一个进程
                if (\Swoole\Process::kill($runProcessMetaItem->pid, 0) &&  time() > ($timeOut + $startTime)) {
                    $isNext = true;
                    break;
                }
            }
            // 重新push进channel
            foreach ($itemList as $runProcessMetaItem) {
                $this->channel->push($runProcessMetaItem);
            }
            // 满足直接return
            if (isset($isNext)) {
                return $isNext;
            }

            if ($this->channel->length() < $this->concurrent) {
                $isNext = true;
            } else {
                System::sleep(0.3);
                $isNext = false;
            }
            $this->debug("进入isNextHandle()方法，channel length=".$this->channel->length());
            if ($isNext) {
                $this->debug("暂时未达到最大的并发进程，满足时间点触发，继续拉起新进程，isNextHandle() return true");
            }else {
                $this->debug("已达到最大的并发进程数，将禁止继续拉起进程，isNextHandle() return false");
            }

        } else {
            $this->debug("暂时未达到最大的并发进程，满足时间点触发，继续拉起新进程，isNextHandle() return true");
            $isNext = true;
        }

        return $isNext ?? false;
    }

    /**
     * @param array $args
     * @return string
     */
    protected function parseEscapeShellArg(array $args)
    {
        if (empty($args)) {
            return "";
        }
        // 关联数组
        if ((function_exists('array_is_list') && array_is_list($args)) || (count(array_keys($args)) > 0 && !isset($args[0]))) {
            foreach ($args as $argvName=>$argvValue) {
                if (str_contains($argvValue, ' ')) {
                    $argvOptions[] = "--{$argvName}='{$argvValue}'";
                }else {
                    $argvOptions[] = "--{$argvName}={$argvValue}";
                }
            }
        }
        return implode(' ', $args);
    }

    /**
     * 获取正在运行的fork进程列表
     *
     * @return array
     */
    public function getRunningForkProcess()
    {
        $allItemList = $runningItemList = [];
        /**
         * @var RunProcessMetaDto $runProcessMetaItem
         */
        if ($this->channel instanceof Channel) {
            while ($runProcessMetaItem = $this->channel->pop(0.02)) {
                $allItemList[] = $runProcessMetaItem;
                $pid = $runProcessMetaItem->pid;
                if (\Swoole\Process::kill($pid, 0)) {
                    $runningItemList[] = $runProcessMetaItem;
                }
            }

            // channel取出来判断running的进程后，需要再次push回去channel
            foreach ($allItemList as $runProcessMetaItem) {
                $this->channel->push($runProcessMetaItem, 0.1);
            }
        }
        return $runningItemList;
    }

    /**
     * 回收已经退出的进程
     *
     * @return array
     */
    public function gcExitProcess()
    {
        $itemList = [];
        /**
         * @var RunProcessMetaDto $runProcessMetaItem
         */
        if ($this->channel instanceof Channel) {
            while ($runProcessMetaItem = $this->channel->pop(0.02)) {
                $pid = $runProcessMetaItem->pid;
                // pid文件存在，但进程已经退出，删除pid文件
                $pidFile = $runProcessMetaItem->pid_file;
                if (!empty($pidFile)) {
                    // 脚本启动进程时，会在当前目录生成pid文件，存在此进程pid文件，说明进程已生成pidFile了
                    if (file_exists($pidFile)) {
                        $pid = (int)file_get_contents($pidFile);
                        if (!\Swoole\Process::kill($pid, 0)) {
                            unlink($pidFile);
                        }else {
                            // 进程已正常
                            $runProcessMetaItem->pid = $pid;
                            $runProcessMetaItem->check_total_count++;
                            $itemList[] = $runProcessMetaItem;
                        }
                    }else {
                        // 检测10次后，依然没有pid文件生成，说明此时脚本的启动已经可能异常了
                        if ($runProcessMetaItem->check_pid_not_exist_count < 10) {
                            $runProcessMetaItem->check_total_count++;
                            $runProcessMetaItem->check_pid_not_exist_count++;
                            $itemList[] = $runProcessMetaItem;
                        }
                    }
                }else {
                    if (\Swoole\Process::kill($pid, 0)) {
                        $runProcessMetaItem->check_total_count++;
                        $itemList[] = $runProcessMetaItem;
                    }
                }
            }

            foreach ($itemList as $runProcessMetaItem) {
                $this->channel->push($runProcessMetaItem, 0.1);
            }

            $this->debug("定时检查运行中进程数量=".$this->channel->length());
        }
        return $itemList;
    }


    /**
     * @return void
     */
    public function registerTickOfCheckRunningProcess()
    {
        static::$checkRunningTickerChannel = goTick($this->checkTickerTime * 1000, function () {
            /**@var CronForkRunner $runner */
            foreach (static::$instances as $runner) {
                $runner->gcExitProcess();
            }
        });
    }

    /**
     * 注册定时器定时删除已退出进程的pidFile
     * @return void
     */
    public function registerTickOfDeleteExistPidFile()
    {
        static::$deleteExistTickerChannel = goTick($this->deleteExistTickerTime * 1000, function () {
            /**
             * @var CronForkRunner $runner
             */
            foreach (static::$instances as $runner) {
                $processList = $runner->gcExitProcess();
                if ($processList) {
                    /**
                     * @var RunProcessMetaDto $runProcessMetaDto
                     */
                    $runProcessMetaDto = array_shift($processList);
                    if (!empty($runProcessMetaDto->pid_file)) {
                        $path = pathinfo($runProcessMetaDto->pid_file, PATHINFO_DIRNAME);
                        if (is_dir($path)) {
                            $files = scandir($path);
                            $files = array_diff($files, array('.', '..'));
                            foreach ($files as $file) {
                                $fullPathPidFile = $path . '/' . $file;
                                if (is_file($fullPathPidFile) && str_contains($file, '.pid')) {
                                    $pid = (int)file_get_contents($fullPathPidFile);
                                    if (($pid > 0 && !\Swoole\Process::kill($pid, 0)) || empty($pid)) {
                                        $this->debug("删除进程退出的pid文件：{$fullPathPidFile}");
                                        @unlink($fullPathPidFile);
                                    }
                                }
                            }
                            break;
                        }
                    }
                }
            }
        });
    }

    /**
     * @throws \Exception
     */
    protected function checkNextFlag()
    {
        if (!$this->isNextFlag) {
            throw new SystemException('Missing call isNextHandle().');
        }
        $this->isNextFlag = false;
    }

    protected function debug(string $info)
    {
        if (env('CRON_DEBUG')) {
            fmtPrintNote($info, false);
        }
    }

    /**
     * __clone
     * @throws \Exception
     */
    private function __clone()
    {
        throw new SystemException("Unable to clone.");
    }

}