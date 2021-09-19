<?php
/**
+----------------------------------------------------------------------
| swoolefy framework bases on swoole extension development, we can use it easily!
+----------------------------------------------------------------------
| Licensed ( https://opensource.org/licenses/MIT )
+----------------------------------------------------------------------
| @see https://github.com/bingcool/swoolefy
+----------------------------------------------------------------------
 */

namespace Swoolefy\Core;

use Swoole\Coroutine\Channel;
use Swoole\Coroutine\System;

class CommandRunner {
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
    static $exitCodes = [
        0 => 'OK',
        1 => 'General error',
        2 => 'Misuse of shell builtins',

        126 => 'Invoked command cannot execute',
        127 => 'Command not found',
        128 => 'Invalid exit argument',

        // signals
        129 => 'Hangup',
        130 => 'Interrupt',
        131 => 'Quit and dump core',
        132 => 'Illegal instruction',
        133 => 'Trace/breakpoint trap',
        134 => 'Process aborted',
        135 => 'Bus error: "access to undefined portion of memory object"',
        136 => 'Floating point exception: "erroneous arithmetic operation"',
        137 => 'Kill (terminate immediately)',
        138 => 'User-defined 1',
        139 => 'Segmentation violation',
        140 => 'User-defined 2',
        141 => 'Write to pipe with no one reading',
        142 => 'Signal raised by alarm',
        143 => 'Termination (request to terminate)',
        // 144 - not defined
        145 => 'Child process terminated, stopped (or continued*)',
        146 => 'Continue if stopped',
        147 => 'Stop executing temporarily',
        148 => 'Terminal stop signal',
        149 => 'Background process attempting to read from tty ("in")',
        150 => 'Background process attempting to write to tty ("out")',
        151 => 'Urgent data available on socket',
        152 => 'CPU time limit exceeded',
        153 => 'File size limit exceeded',
        154 => 'Signal raised by timer counting virtual time: "virtual timer expired"',
        155 => 'Profiling timer expired',
        // 156 - not defined
        157 => 'Pollable event',
        // 158 - not defined
        159 => 'Bad syscall',
    ];

    /**
     * @param string $runnerName
     * @param int $concurrent
     * @return CommandRunner
     */
    public static function getInstance(string $runnerName, int $concurrent = 5)
    {
        if(!isset(static::$instances[$runnerName]))
        {
            /**@var CommandRunner $runner*/
            $runner = new static();
            if($concurrent >= 10)
            {
                $concurrent = 10;
            }
            $runner->concurrent = $concurrent;
            $runner->channel = new Channel($runner->concurrent);
            static::$instances[$runnerName] = $runner;
        }else {
            /**@var CommandRunner $runner*/
            $runner = static::$instances[$runnerName];
        }

        return $runner;
    }

    /**
     * 执行外部系统程序，包括php,shell so on
     * 禁止swoole提供的process->exec，因为swoole的process->exec调用的程序会替换当前子进程
     * @param string $execBinFile
     * @param string $commandRouter
     * @param array $args
     * @param bool $async
     * @param string $log
     * @param bool $isExec
     * @throws Exception
     * @return array
     */
    public function exec(
        string $execBinFile,
        string $commandRouter,
        array $args = [],
        bool $async = false,
        string $log = '/dev/null',
        bool $isExec = true
    ) {
        $this->checkNextFlag();
        $params = '';
        if($args) {
            $params = $this->parseEscapeShellArg($args);
        }

        $path = $execBinFile.' '.$commandRouter.' '.$params;
        $command = "{$path} >> {$log} 2>&1 && echo $$";
        if($async) {
            // echo $! 表示输出进程id赋值在output数组中
            $command = "nohup {$path} >> {$log} 2>&1 & echo $!";
        }

        if($isExec)
        {
            exec($command,$output,$return);
            $pid = $output[0] ?? '';
            if($pid)
            {
                $this->channel->push([
                    'pid' => $pid,
                    'command' => $command,
                    'start_time' => time()
                ],0.2);
            }
            // when exec error save log
            if($return != 0) {
                throw new \Exception("CommandRunner Exec failed,reurnCode={$return},commandLine={$command}.");
            }
        }

        return [$command, $output ?? [], $return ?? -1];
    }

    /**
     * @param callable $callable
     * @param string $execBinFile
     * @param array $args
     * @return mixed
     * @throws Exception
     */
    public function procOpen(
        callable $callable,
        string $execBinFile,
        array $args = []
    ) {
        $this->checkNextFlag();
        $params = '';
        if($args) {
            $params = $this->parseEscapeShellArg($args);
        }

        $command = $execBinFile.' '.$params.'; echo $? >&3';
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

        \Swoole\Coroutine::create(function () use($callable, $command, $descriptors) {
            // in $callable forbidden create coroutine, because $proc_process had been bind in current coroutine
            try {
                $proc_process = proc_open($command, $descriptors, $pipes);
                if(!is_resource($proc_process)) {
                    throw new \Exception("Proc Open Command 【{$command}】 failed.");
                }
                $status = proc_get_status($proc_process);
                if($status['pid'] ?? '') {
                    $this->channel->push([
                        'pid' => $status['pid'],
                        'command' => $command,
                        'start_time' => time()
                    ],0.2);

                    $returnCode = fgets($pipes[3],10);
                    if($returnCode != 0) {
                        throw new \Exception("CommandRunner Proc Open failed,reurnCode={$return},commandLine={$command}.");
                    }
                }
                $params = [$pipes[0], $pipes[1], $pipes[2], $status, $returnCode ?? -1];
                return call_user_func_array($callable, $params);
            }catch (\Throwable $e) {
                throw $e;
            }finally {
                foreach($pipes as $pipe) {
                    @fclose($pipe);
                }
                proc_close($proc_process);
            }
        });

    }

    /**
     * @return bool
     */
    public function isNextHandle()
    {
        $this->isNextFlag = true;
        if($this->channel->isFull())
        {
            $pids = [];
            while($item = $this->channel->pop(0.05))
            {
                $pid = $item['pid'];
                $startTime = $item['start_time'];
                if(\Swoole\Process::kill($pid,0) && ($startTime + 60) > time() )
                {
                    $pids[] = $item;
                }
            }

            foreach($pids as $item)
            {
                $this->channel->push($item,0.1);
            }

            if($this->channel->length() < $this->concurrent)
            {
                $isNext = true;
            }else
            {
                System::sleep(0.1);
            }
        }else
        {
            $isNext = true;
        }

        return $isNext ?? false;
    }

    /**
     * @throws Exception
     */
    protected function checkNextFlag()
    {
        if(!$this->isNextFlag) {
            throw new \Exception('Missing call isNextHandle().');
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
     */
    private function __clone()
    {
        throw new \Exception("Unable to clone.");
    }

}