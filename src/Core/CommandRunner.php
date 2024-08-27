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

use Swoole\Coroutine\Channel;
use Swoole\Coroutine\System;
use Swoole\Process;
use Swoolefy\Exception\SystemException;

class CommandRunner
{
    /**
     * @var array
     */
    protected static $instances = [];

    /**
     * @var Channel
     */
    protected $channel;

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
     * @param bool $async
     * @param string $log
     * @param bool $isExec
     * @return array
     * @throws SystemException
     */
    public function exec(
        string $execBinFile,
        string $execScript,
        array  $args = [],
        bool   $async = false,
        string $log = '/dev/null',
        bool   $isExec = true
    )
    {
        $this->checkNextFlag();
        $params = '';
        if ($args) {
            $params = $this->parseEscapeShellArg($args);
        }

        $path = $execBinFile . ' ' . $execScript . ' ' . $params;
        $path = trim($path,' ');
        if ($log) {
            $command = "{$path} >> {$log} 2>&1 & echo $$";
        }else {
            $command = "{$path} 2>&1 & echo $$";
        }

        if ($async) {
            // echo $! 表示输出进程id赋值在output数组中
            if ($log) {
                $command = "nohup {$path} >> {$log} 2>&1 & echo $!";
            }else {
                $command = "nohup {$path} 2>&1 & echo $!";
            }
        }

        if ($isExec) {
            $exec = (new Exec())->run($command);
            $output = $exec->getOutput();
            $returnCode = $exec->getReturnCode();
            $pid = $output[0] ?? '';
            if (!$this->channel instanceof Channel) {
                $this->channel = new Channel($this->concurrent);
            }

            if ($pid) {
                $this->channel->push([
                    'pid' => $pid,
                    'command' => $command,
                    'start_time' => time()
                ], 0.2);
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
     * @param callable $callable
     * @param string $execBinFile
     * @param string $execScript
     * @param array $args
     * @return mixed
     * @throws SystemException
     */
    public function procOpen(
        callable $callable,
        string   $execBinFile,
        string   $execScript,
        array    $args = []
    )
    {
        $this->checkNextFlag();
        $params = '';
        if ($args) {
            $params = $this->parseEscapeShellArg($args);
        }

        $command = $execBinFile .' '.$execScript.' ' . $params . '; echo $? >&3';
        $command = trim($command,' ');
        $descriptors = array(
            // stdout
            0 => array('pipe', 'r'),
            // stdin
            1 => array('pipe', 'w'),
            // stderr
            2 => array('pipe', 'w'),
            // return code
            3 => array('pipe', 'w')
        );

        $fn = function ($command, $descriptors, $callable) {
            // in $callable forbidden create coroutine, because $proc_process had been bind in current coroutine
            try {
                $proc_process = proc_open($command, $descriptors, $pipes);
                if (!is_resource($proc_process)) {
                    throw new SystemException("Proc Open Command 【{$command}】 failed.");
                }
                $status = proc_get_status($proc_process);
                $statusProperty = [
                    'pid' => $status['pid'] ?? '',
                    'command' => $command,
                    'start_time' => time()
                ];

                // 协程环境设置channel控制并发数，isNextHandle()函数判断是否可以并发拉起下一个进程
                if (isset($status['pid']) && $status['pid'] > 0 && \Swoole\Coroutine::getCid() >= 0) {
                    if (!$this->channel instanceof Channel) {
                        $this->channel = new Channel($this->concurrent);
                    }
                    $this->channel->push($statusProperty, 0.2);
                }else {
                    if (!Process::kill($status['pid'], 0)) {
                        $returnCode = fgets($pipes[3], 10);
                        $errorMsg = static::$exitCodes[$returnCode] ?? 'Unknown Error';
                        throw new SystemException("CommandRunner Proc Open failed,return Code={$returnCode},commandLine={$command}, errorMsg={$errorMsg}.");
                    }
                }
                $params = [$pipes[0], $pipes[1], $pipes[2], $statusProperty];
                $result = call_user_func_array($callable, $params);
                return $result;
            } catch (\Throwable $e) {
                fmtPrintError("CommandRunner ErrorMsg={$e->getMessage()},trace={$e->getTraceAsString()}");
            } finally {
                foreach ($pipes as $pipe) {
                    @fclose($pipe);
                }
                proc_close($proc_process);
            }
        };

        if (\Swoole\Coroutine::getCid() >= 0) {
            goApp(function () use ($fn, $callable, $command, $descriptors) {
                $fn($command, $descriptors, $callable);
            });
        }else {
            $fn1 = function ($command, $descriptors, $callable) {
                try {
                    $proc_process = proc_open($command, $descriptors, $pipes);
                    if (!is_resource($proc_process)) {
                        throw new SystemException("Proc Open Command 【{$command}】 failed.");
                    }
                    $status = proc_get_status($proc_process);
                    $statusProperty = [
                        'pid' => $status['pid'] ?? '',
                        'command' => $command,
                        'start_time' => time()
                    ];
                    $params = [$pipes[0], $pipes[1], $pipes[2], $statusProperty];
                    $result = call_user_func_array($callable, $params);
                    return $result;
                }catch (\Throwable $e) {
                    fmtPrintError("CommandRunner ErrorMsg={$e->getMessage()},trace={$e->getTraceAsString()}");
                } finally {
                    foreach ($pipes as $pipe) {
                        @fclose($pipe);
                    }
                    proc_close($proc_process);
                }
            };

            $fn1($command, $descriptors, $callable);
        }
    }

    /**
     * @param bool $isNeedCheck 是否需要控制并发拉起进程，在while循环中，一般需要调用该函数来控制并发拉起进程。不控制的话，将瞬间拉起大量进程，导致系统崩溃.eg:
     * while(true){
     *    // 是否满足拉起下一个进程.这个函数主要是判断拉起的进程数是否已达到最大并发数.
     *    if(CommandRunner::getInstance()->isNextHandle(true,60)) {
     *          // todo
     *          CommandRunner::getInstance()->procOpen(function($pipes,$statusProperty){
     *              todo
     *          },'/bin/sh','ls -l')
     *      }
     * }
     * @param int $timeOut 规定时间内达到，强制拉起下一个进程
     * @return bool
     */
    public function isNextHandle(bool $isNeedCheck = true, int $timeOut = 60)
    {
        $this->isNextFlag = true;
        if ($this->channel instanceof Channel && $this->channel->isFull() && $isNeedCheck) {
            $itemList = [];
            while ($item = $this->channel->pop(0.05)) {
                $pid = $item['pid'];
                $startTime = $item['start_time'];
                // $timeOut 内还未执行完的进程才统计，重新推入channel.超过60s的进程即使还存在，也算执行完毕了。
                if (\Swoole\Process::kill($pid, 0) && time() < ($startTime + $timeOut)) {
                    $itemList[] = $item;
                }
            }

            foreach ($itemList as $item) {
                $this->channel->push($item, 0.1);
            }

            if ($this->channel->length() < $this->concurrent) {
                $isNext = true;
            } else {
                System::sleep(0.3);
            }
        } else {
            $isNext = true;
        }

        return $isNext ?? false;
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

    /**
     * @param array $args
     * @return string
     */
    protected function parseEscapeShellArg(array $args)
    {
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