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
            $runner->channel = new Channel($runner->concurrent);
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
        $command = "{$path} >> {$log} 2>&1 && echo $$";
        if ($async) {
            // echo $! 表示输出进程id赋值在output数组中
            $command = "nohup {$path} >> {$log} 2>&1 & echo $!";
        }

        if ($isExec) {
            $exec = (new Exec())->run($command);
            $output = $exec->getOutput();
            $returnCode = $exec->getReturnCode();
            $pid = $output[0] ?? '';
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

        goApp(function () use ($callable, $command, $descriptors) {
            // in $callable forbidden create coroutine, because $proc_process had been bind in current coroutine
            try {
                $proc_process = proc_open($command, $descriptors, $pipes);
                if (!is_resource($proc_process)) {
                    throw new SystemException("Proc Open Command 【{$command}】 failed.");
                }
                $status = proc_get_status($proc_process);
                if ($status['pid'] ?? '') {
                    $this->channel->push([
                        'pid' => $status['pid'],
                        'command' => $command,
                        'start_time' => time()
                    ], 0.2);

                    $returnCode = fgets($pipes[3], 10);
                    if ($returnCode != 0) {
                        $errorMsg = static::$exitCodes[$returnCode] ?? 'Unknown Error';
                        throw new SystemException("CommandRunner Proc Open failed,return Code={$returnCode},commandLine={$command}, errorMsg={$errorMsg}.");
                    }
                }
                $params = [$pipes[0], $pipes[1], $pipes[2], $status, $returnCode ?? -1];
                $result = call_user_func_array($callable, $params);
                return $result;
            } catch (\Throwable $e) {
                write("【Error】CommandRunner ErrorMsg={$e->getMessage()},trace={$e->getTraceAsString()}");
            } finally {
                foreach ($pipes as $pipe) {
                    @fclose($pipe);
                }
                proc_close($proc_process);
            }
        });

    }

    /**
     * @param bool $isNeedCheck
     * @return bool
     */
    public function isNextHandle(bool $isNeedCheck = true)
    {
        $this->isNextFlag = true;
        if ($this->channel->isFull() && $isNeedCheck) {
            $itemList = [];
            while ($item = $this->channel->pop(0.05)) {
                $pid = $item['pid'];
                $startTime = $item['start_time'];
                if (\Swoole\Process::kill($pid, 0) && ($startTime + 60) > time()) {
                    $itemList[] = $item;
                }
            }

            foreach ($itemList as $item) {
                $this->channel->push($item, 0.1);
            }

            if ($this->channel->length() < $this->concurrent) {
                $isNext = true;
            } else {
                System::sleep(0.1);
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
        return implode(' ', array_map('escapeshellarg', $args));
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