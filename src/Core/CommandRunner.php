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

namespace Swoolefy\Core;

use Swoole\Coroutine\System;
use Swoole\Process;
use Swoolefy\Worker\Dto\RunProcessMetaDto;
use Swoolefy\Exception\SystemException;

class CommandRunner
{
    /**
     * @var array
     */
    protected static $instances = [];

    /**
     * @var array
     */
    protected $runProcessMetaPool = [];

    /**
     * @var int
     */
    protected $concurrent = 5;

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
     * @return CommandRunner
     */
    public static function getInstance(string $runnerName, int $concurrent = 5)
    {
        if (!isset(static::$instances[$runnerName])) {
            /**@var CommandRunner $runner */
            $runner = new static();
            if ($concurrent >= 10) {
                $concurrent = 10;
            }
            $runner->concurrent = $concurrent;
            static::$instances[$runnerName] = $runner;
        }

        return static::$instances[$runnerName];
    }


    /**
     * 执行外部系统程序，包括php,shell so on
     * 禁止swoole提供的process->exec，因为swoole的process->exec调用的程序会替换当前子进程
     * @param string $execBinFile
     * @param string $execScript
     * @param array $args
     * @param bool $async 脚本设置nohup异步模式时，最好要设置output,如果不需要输出到文件，可以设置成/dev/null
     * @param string $output
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
        string $output = '/dev/null',
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
        if ($output) {
            $command = "{$path} >> {$output} 2>&1; echo $$";
        }else {
            $command = "{$path} 2>&1; echo $$";
        }

        if ($async) {
            // echo $! 表示输出进程id赋值在output数组中
            if ($output) {
                $command = "nohup {$path} >> {$output} 2>&1 & \n echo $!";
            }else {
                $command = "nohup {$path} 2>&1 & \n echo $!";
            }
        }

        if ($isExec) {
            $exec       = (new Exec())->run($command);
            $execOutput = $exec->getOutput();
            $returnCode = $exec->getReturnCode();
            $pid        = $execOutput[0] ?? '';
            if ($pid) {
                $runProcessMetaDto = new RunProcessMetaDto();
                $runProcessMetaDto->pid = (int)trim($pid);
                $runProcessMetaDto->command = $command;
                $runProcessMetaDto->pid_file = '';
                $runProcessMetaDto->check_total_count = 0;
                $runProcessMetaDto->check_pid_not_exist_count = 0;
                $runProcessMetaDto->start_timestamp = time();
                $runProcessMetaDto->start_date_time = date('Y-m-d H:i:s');
                $this->runProcessMetaPool[] = $runProcessMetaDto;
            }
            // when exec error save log
            if ($returnCode != 0) {
                $errorMsg = static::$exitCodes[$returnCode] ?? 'Unknown Error';
                throw new SystemException("CommandRunner Exec failed,return code ={$returnCode},commandLine={$command},errorMsg={$errorMsg}.");
            }
        }

        return [$command, $execOutput ?? [], $returnCode ?? -1, (int)$pid ?? 0];
    }

    /**
     * @param string $execBinFile
     * @param string $execScript
     * @param array $args
     * @param callable $callable
     * @param array $extend
     * @param bool $async
     * @return mixed
     * @throws SystemException
     */
    public function procOpen(
        string   $execBinFile,
        string   $execScript,
        array    $args = [],
        ?callable $callable = null,
        array    $extend = [],
        bool     $async = false
    )
    {
        $this->checkNextFlag();
        $argvOption = '';
        if ($args) {
            $argvOption = $this->parseEscapeShellArg($args);
        }
        if (!str_starts_with($execBinFile, 'nohup') && $async) {
            $execScript = str_replace( '2>&1'," ", $execScript);
            $execScript = rtrim($execScript, '&');
            $execScript = $execScript.' ' . $argvOption.' 2>&1 &';
        }
        $command = $execBinFile .' '.$execScript.' ' . $argvOption . " \n echo $? >&3; echo $! >&4; echo $$ >&5;";
        $command = trim($command);
        $descriptors = array(
            // stdin is a pipe that the child process will read from, parent process write
            0 => array('pipe', 'r'),
            // stdout is a pipe that the child process will write to, parent process read
            1 => array('pipe', 'w'),
            // stderr
            2 => array('pipe', 'w'),
            // return exist code
            3 => array('pipe', 'w'),
            // 后台运行的进程的pid,eg: nohup的后台进程
            4 => array('pipe', 'w'),
            // 当前运行的进程的pid,eg：ls -l
            5 => array('pipe', 'w'),
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
                if ($status['pid'] == 0) {
                    $status['pid'] = (int)trim(fgets($pipes[5], 10));
                }

                $runProcessMetaDto = new RunProcessMetaDto();
                $runProcessMetaDto->pid = $status['pid'] ?? 0;
                $runProcessMetaDto->command = $command;
                $runProcessMetaDto->pid_file = '';
                $runProcessMetaDto->check_total_count = 0;
                $runProcessMetaDto->check_pid_not_exist_count = 0;
                $runProcessMetaDto->start_timestamp = time();
                $runProcessMetaDto->start_date_time = date('Y-m-d H:i:s');

                $this->runProcessMetaPool[] = $runProcessMetaDto;
                if (!Process::kill($status['pid'], 0)) {
                    $returnCode = fgets($pipes[3], 10);
                    $errorMsg   = static::$exitCodes[$returnCode] ?? 'Unknown Error';
                    throw new SystemException("CommandRunner Proc Open failed,return Code={$returnCode},commandLine={$command}, errorMsg={$errorMsg}.");
                }
                $statusProperty = $runProcessMetaDto->toArray();
                $params = [$pipes[0], $pipes[1], $pipes[2], $statusProperty];
                call_user_func_array($callable, $params);
                return $statusProperty;
            } catch (\Throwable $e) {
                $msg = "CommandRunner ErrorMsg={$e->getMessage()},trace={$e->getTraceAsString()}";
                fmtPrintError($msg);
                throw new SystemException($msg);
            } finally {
                foreach ($pipes as $pipe) {
                    @fclose($pipe);
                }
                proc_close($proc_process);
            }
        };

        if (!is_callable($callable)) {
            $callable = function ($pipe0, $pipe1, $pipe2, $statusProperty) {
                // $output = stream_get_contents($pipe1);
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
     *         $runner->procOpen('ls -l','',[])
     *     }
     * })
     * @param int $timeOut 规定时间内达到，强制拉起下一个进程
     * @return bool
     */
    public function isNextHandle(bool $isNeedCheck = true, int $timeOut = 60)
    {
        $this->isNextFlag = true;
        $this->gcExitProcess();
        if (count($this->runProcessMetaPool) >= $this->concurrent && $isNeedCheck) {
            $exitProcess = [];
            /**
             * @var RunProcessMetaDto $runProcessMetaItem
             */
            foreach ($this->runProcessMetaPool as $runProcessMetaItem) {
                $startTime  = strtotime($runProcessMetaItem->start_time);
                // 进程已经存在，并且已经执行超过了规定时间,强制拉起下一个进程
                if (\Swoole\Process::kill($runProcessMetaItem->pid, 0) &&  time() > ($timeOut + $startTime)) {
                    $isNext = true;
                    break;
                }

                // 已退出进程
                if (!\Swoole\Process::kill($runProcessMetaItem->pid, 0)) {
                    $exitProcess[] = $runProcessMetaItem;
                }
            }

            if (!isset($isNext)) {
                // 所有的进程都已退出
                if (count($this->runProcessMetaPool) == count($exitProcess)) {
                    $isNext = true;
                    // 清空进程元信息池
                    $this->runProcessMetaPool = [];
                }else {
                    System::sleep(0.3);
                    $isNext = false;
                }
            }
        } else {
            $isNext = true;
        }

        return $isNext ?? false;
    }

    /**
     * 获取正在运行的fork进程列表
     *
     * @return array
     */
    public function getRunningForkProcess()
    {
        $runningItemList = [];
        /**
         * @var RunProcessMetaDto $runProcessMetaItem
         */
        foreach ($this->runProcessMetaPool as $runProcessMetaItem) {
            $pid = $runProcessMetaItem->pid;
            if (\Swoole\Process::kill($pid, 0)) {
                $runningItemList[] = $runProcessMetaItem;
            }
        }
        return $runningItemList;
    }

    /**
     * 回收已经退出的进程
     *
     * @return array
     */
    protected function gcExitProcess()
    {
        $itemList = [];
        /**
         * @var RunProcessMetaDto $runProcessMetaItem
         */
        if (count($this->runProcessMetaPool) > 0) {
            foreach ($this->runProcessMetaPool as $runProcessMetaItem) {
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

            // 最新的存在的进程元信息池
            $this->runProcessMetaPool = $itemList;
        }
        return $itemList;
    }

    /**
     * @throws \Exception
     */
    protected function checkNextFlag()
    {
        if (!$this->isNextFlag) {
            throw new SystemException('Missing call isNextHandle().');
        }
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
        foreach ($args as $argvName=>$argvValue) {
            if (is_string($argvName)) {
                if (str_contains($argvValue, ' ')) {
                    $argvOptions[] = "--{$argvName}='{$argvValue}'";
                }else {
                    $argvOptions[] = "--{$argvName}={$argvValue}";
                }
            }else if (is_numeric($argvName)) {
                $argvOptions[] = $argvValue;
            }
        }

        if (!empty($argvOptions)) {
            $args = $argvOptions;
        }
        return implode(' ', $args);
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