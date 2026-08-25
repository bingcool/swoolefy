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
use Swoolefy\Core\Exec;
use Swoolefy\Worker\Dto\RunProcessMetaDtoWorker;
use Swoolefy\Worker\Dto\CronForkTaskMetaDtoWorker;
use Swoolefy\Exception\SystemException;
use Swoolefy\Script\AbstractKernel;

/**
 * Shell 子进程拉起与并发限制。
 *
 * 调度只属于 CronManager。本类由 CronForkProcess::executeCronSnapshot()
 * 经 ShellExecutor 的 forkRunner 调用：按 cron_name 单例、限制 concurrent、
 * proc_open / exec。只负责“怎么 fork”，不负责 Timer / Diff / Guard。
 *
 * 不变量：
 * - 每个 cron_name 一个 Runner 实例（getInstance / removeRunner）
 * - isNextHandle() 在达到 concurrent 时拒绝新 fork
 * - PID 记录在 runProcessMetaPool，供 with_block_lapping 与 GC
 *
 * @see CronForkProcess::executeCronSnapshot()
 * @see ShellExecutor
 */
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
     * @var string
     */
    protected $cronName = '';

    /**
     * @var array
     */
    protected $runProcessMetaPool = [];

    /**
     * @var int
     */
    protected $concurrent = 5;

    /**
     * @var int
     */
    protected $checkTickerTime = 20;

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
     * fork fail code
     * @var int
     */
    const FORK_FAIL_CODE = 10000;

    /**
     * 按 runnerName（通常 md5(cron_name)）取单例。concurrent 上限 10。
     * 首次创建时注册 GC tick（检查仍在跑的 fork、清理残留 pid 文件）。
     *
     * @param string $runnerName
     * @param int $concurrent
     * @param string $cronName
     * @return CronForkRunner
     */
    public static function getInstance(string $runnerName, int $concurrent = 5, string $cronName = '')
    {
        if (!isset(static::$instances[$runnerName])) {
            /**@var CronForkRunner $runner */
            $runner = new static();
            if ($concurrent >= 10) {
                $concurrent = 10;
            }

            if (!empty($cronName)) {
                $runner->cronName = $cronName;
            }else {
                $runner->cronName = $runnerName;
            }

            $runner->concurrent = $concurrent;
            static::$instances[$runnerName] = $runner;
        } else {
            $runner = static::$instances[$runnerName];
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
     * @param $runnerName
     * @return void
     */
    public function setCronName($runnerName)
    {
        $this->cronName = $runnerName;
    }

    /**
     * 显式释放 Runner 单例。CronManager DELETE 不自动清 Runner，
     * 执行器按需 getInstance；Worker 停机或测试清理时可调用本方法。
     *
     * @param string $runnerName
     * @return void
     */
    public static function removeRunner(string $runnerName)
    {
        if (isset(static::$instances[$runnerName])) {
            self::debug("Remove runner:{$runnerName}");
            unset(static::$instances[$runnerName]);
        }
    }

    /**
     * 仅在 Runner 无运行中进程时释放实例，避免删除后立刻复用时丢失并发上下文。
     */
    public static function removeRunnerIfIdle(string $runnerName): bool
    {
        if (!isset(static::$instances[$runnerName])) {
            return true;
        }
        $runner = static::$instances[$runnerName];
        $runner->gcExitProcess();
        if (count($runner->runProcessMetaPool) > 0) {
            return false;
        }
        self::debug("Remove idle runner:{$runnerName}");
        unset(static::$instances[$runnerName]);

        return true;
    }

    /**
     * Worker stop 可强制清理全部 Runner；默认仅清理 idle Runner。
     *
     * @return int 已释放 Runner 数量
     */
    public static function removeAllRunners(bool $force = false): int
    {
        $removed = 0;
        foreach (array_keys(static::$instances) as $runnerName) {
            if ($force) {
                static::removeRunner($runnerName);
                $removed++;
                continue;
            }
            if (static::removeRunnerIfIdle($runnerName)) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * 执行外部系统程序，包括php,shell so on
     * 禁止swoole提供的process->exec，因为swoole的process->exec调用的程序会替换当前子进程
     * @param string $execBinFile
     * @param string $execScript
     * @param array $args
     * @param bool $async
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
            $pid        = $execOutput[0] ?? -1;

            if ($pid) {
                $runProcessMetaDto = $this->createRunProcessMeta((int)trim($pid), $command);
                $cronScriptPidFileOption = AbstractKernel::getCronScriptPidFileOptionField();
                if (isset($extend[$cronScriptPidFileOption])) {
                    $cronScriptPidFile = $extend[$cronScriptPidFileOption];
                    if (is_file($cronScriptPidFile)) {
                        $runProcessMetaDto->pid = (int)trim(file_get_contents($cronScriptPidFile));
                    }else {
                        $runProcessMetaDto->pid = 0;
                    }
                    $runProcessMetaDto->pid_file = $cronScriptPidFile;
                    $this->debug("【{$this->cronName}】拉起新的进程pid_file:".$runProcessMetaDto->pid_file);
                }else {
                    $this->debug("【{$this->cronName}】拉起新的进程pid:".$runProcessMetaDto->pid);
                }
                $this->runProcessMetaPool[] = $runProcessMetaDto;
            }
            // when exec error save log
            if ($returnCode != 0) {
                $errorMsg = static::$exitCodes[$returnCode] ?? 'Unknown Error';
                throw new SystemException("【{$this->cronName}】CommandRunner Exec failed,return code ={$returnCode},commandLine={$command},errorMsg={$errorMsg}.");
            }
        }

        return [$command, $execOutput ?? [], $returnCode ?? -1, $pid ?? 0];
    }

    /**
     * @param string $execBinFile
     * @param string $execScript
     * @param array $args
     * @param callable $callable
     * @param array $extend
     * @return array|null
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

        $scheduleModelOptionField = AbstractKernel::getScheduleModelOptionField();
        $runType = 'other';
        if ((isset($extend['run_type']) && $extend['run_type'] == CronForkTaskMetaDtoWorker::RUN_TYPE)
            || (isset($extend[$scheduleModelOptionField]) && str_contains(strtolower((string) $extend[$scheduleModelOptionField]), 'cron'))) {
            $runType = CronForkTaskMetaDtoWorker::RUN_TYPE;
        }
        $execScript = str_replace('2>&1', ' ', $execScript);
        $execScript = rtrim($execScript, '&');
        $command = trim($execBinFile . ' ' . $execScript . ' ' . $argvOption);

        $command     = trim($command);
        $descriptors = array(
            // stdin
            0 => array('pipe', 'r'),
            // stdout
            1 => array('pipe', 'w'),
            // stderr
            2 => array('pipe', 'w'),
        );

        $fn = function ($command, $descriptors, $callable) use($extend, $runType) {
            // in $callable forbidden create coroutine, because $proc_process had been bind in current coroutine
            $pipes = [];
            $proc_process = null;
            try {
                $proc_process = proc_open($command, $descriptors, $pipes);
                if (!is_resource($proc_process)) {
                    throw new SystemException("【{$this->cronName}】Proc Open Command 【{$command}】 failed.");
                }
                $status = proc_get_status($proc_process);
                $status['pid'] = (int) ($status['pid'] ?? 0);
                if (isset($pipes[0]) && is_resource($pipes[0])) {
                    @fclose($pipes[0]);
                }
                if (isset($pipes[1]) && is_resource($pipes[1])) {
                    @stream_set_blocking($pipes[1], false);
                }
                if (isset($pipes[2]) && is_resource($pipes[2])) {
                    @stream_set_blocking($pipes[2], false);
                }

                $runProcessMetaDto = $this->createRunProcessMeta($status['pid'] ?? -1, $command);

                $cronScriptPidFileOption = AbstractKernel::getCronScriptPidFileOptionField();
                if (isset($extend[$cronScriptPidFileOption]) || $runType == CronForkTaskMetaDtoWorker::RUN_TYPE) {
                    $cronScriptPidFile = $extend[$cronScriptPidFileOption];
                    // 非阻塞轮询 pid 文件：最多等待 10 秒，每 100ms 检查一次。
                    $deadline = microtime(true) + env('CRON_MAX_WAIT_FORK_TIME', 10.0);
                    while (microtime(true) < $deadline) {
                        if (is_file($cronScriptPidFile)) {
                            $pidNum = trim(file_get_contents($cronScriptPidFile));
                            if (is_numeric($pidNum) && $pidNum > 0) {
                                $runProcessMetaDto->pid = $pidNum;
                                break;
                            }
                        }
                        if (\Swoole\Coroutine::getCid() >= 0) {
                            System::sleep(0.1);
                        } else {
                            usleep(100000);
                        }
                    }
                    $runProcessMetaDto->pid_file = $cronScriptPidFile;
                    $this->debug("【{$this->cronName}】拉起新的(swoolefy script)进程pid_file:".$runProcessMetaDto->pid_file);
                } else {
                    $this->debug("【{$this->cronName}】拉起新的进程pid:".$runProcessMetaDto->pid);
                }

                $statusProperty = $runProcessMetaDto->toArray();
                $params = [$pipes[0], $pipes[1], $pipes[2], $statusProperty];

                // 协程环境设置channel控制并发数，isNextHandle()函数判断是否可以并发拉起下一个进程
                if (\Swoole\Coroutine::getCid() >= 0) {
                    $this->runProcessMetaPool[] = $runProcessMetaDto;
                    $this->debug("【{$this->cronName}】拉起后当前runProcessMetaPool的Size=".count($this->runProcessMetaPool));
                }
                $waitResult = $this->waitForProcessExitAndDrainPipes($proc_process, $pipes, $status['pid']);
                $waitResult['pid'] = (int) ($statusProperty['pid'] ?? 0);
                call_user_func_array($callable, $params);
                return $waitResult;
            } catch (\Throwable $e) {
                $msg = "【{$this->cronName}】CommandRunner ErrorMsg={$e->getMessage()},trace={$e->getTraceAsString()}";
                fmtPrintError($msg);
                throw new SystemException($msg, $e->getCode());
            } finally {
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        @fclose($pipe);
                    }
                }
                if (is_resource($proc_process)) {
                    proc_close($proc_process);
                }
            }
        };

        if (!is_callable($callable)) {
            $callable = function ($pipe0, $pipe1, $pipe2, $statusProperty) {

            };
        }

        return $fn($command, $descriptors, $callable);
    }

    /**
     * 持续 drain stdout/stderr 并等待子进程退出，避免 pipe 写满导致 child 阻塞。
     *
     * @param resource $procProcess
     * @param array<int, mixed> $pipes
     * @return array<string, mixed>
     */
    private function waitForProcessExitAndDrainPipes($procProcess, array $pipes, int $pid): array
    {
        $maxCaptureBytes = 1024 * 1024;
        $stdout = '';
        $stderr = '';
        $stdoutTruncated = false;
        $stderrTruncated = false;
        $lastStatus = ['running' => false, 'exitcode' => -1, 'signaled' => false, 'termsig' => 0];

        while (true) {
            $this->appendPipeContent($pipes[1] ?? null, $stdout, $stdoutTruncated, $maxCaptureBytes);
            $this->appendPipeContent($pipes[2] ?? null, $stderr, $stderrTruncated, $maxCaptureBytes);
            $status = proc_get_status($procProcess);
            if (is_array($status)) {
                $lastStatus = $status + $lastStatus;
            }
            if (!$lastStatus['running']) {
                break;
            }
            if (\Swoole\Coroutine::getCid() >= 0) {
                System::sleep(0.01);
            } else {
                usleep(10000);
            }
        }

        // 进程退出后继续读到 EOF，确保不丢尾部输出。
        for ($i = 0; $i < 5; $i++) {
            $before = strlen($stdout) + strlen($stderr);
            $this->appendPipeContent($pipes[1] ?? null, $stdout, $stdoutTruncated, $maxCaptureBytes);
            $this->appendPipeContent($pipes[2] ?? null, $stderr, $stderrTruncated, $maxCaptureBytes);
            $after = strlen($stdout) + strlen($stderr);
            if ($after === $before) {
                break;
            }
            if (\Swoole\Coroutine::getCid() >= 0) {
                System::sleep(0.005);
            } else {
                usleep(5000);
            }
        }

        $exitCode = (int) ($lastStatus['exitcode'] ?? -1);
        if ($exitCode < 0) {
            $closeCode = proc_close($procProcess);
            $exitCode = is_int($closeCode) ? $closeCode : $exitCode;
        }

        return [
            'pid' => $pid,
            'exit_code' => $exitCode,
            'signaled' => !empty($lastStatus['signaled']),
            'term_signal' => (int) ($lastStatus['termsig'] ?? 0),
            'stdout' => $stdout,
            'stderr' => $stderr,
            'stdout_truncated' => $stdoutTruncated,
            'stderr_truncated' => $stderrTruncated,
        ];
    }

    /**
     * 读取并消费管道内容；超过上限后继续读取但不再累计，避免死锁。
     *
     * @param mixed $pipe
     */
    private function appendPipeContent($pipe, string &$buffer, bool &$truncated, int $limitBytes): void
    {
        if (!is_resource($pipe)) {
            return;
        }
        while (true) {
            $chunk = stream_get_contents($pipe);
            if ($chunk === false || $chunk === '') {
                break;
            }
            if ($truncated) {
                continue;
            }
            $remaining = $limitBytes - strlen($buffer);
            if ($remaining <= 0) {
                $truncated = true;
                continue;
            }
            if (strlen($chunk) > $remaining) {
                $buffer .= substr($chunk, 0, $remaining);
                $truncated = true;
                continue;
            }
            $buffer .= $chunk;
        }
    }

    /**
     * @param int $pid
     * @param string $command
     * @param string $pidFile
     * @return RunProcessMetaDtoWorker
     */
    private function createRunProcessMeta(
        int $pid,
        string $command,
        string $pidFile = ''
    ): RunProcessMetaDtoWorker
    {
        $dto = new RunProcessMetaDtoWorker();
        $dto->pid = $pid;
        $dto->command = $command;
        $dto->pid_file = $pidFile;
        $dto->check_total_count = 0;
        $dto->check_pid_not_exist_count = 0;
        $dto->start_timestamp = time();
        $dto->start_date_time = date('Y-m-d H:i:s');
        return $dto;
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
    public function isNextHandle(bool $isNeedCheck = true, int $timeOut = 60): bool
    {
        $this->isNextFlag = true;
        $this->gcExitProcess();
        if (count($this->runProcessMetaPool) >= $this->concurrent && $isNeedCheck) {
            /**
             * @var RunProcessMetaDtoWorker $runProcessMetaItem
             */
            foreach ($this->runProcessMetaPool as $runProcessMetaItem) {
                $startTime  = $runProcessMetaItem->start_timestamp;
                // 进程已经存在，并且已经执行超过了规定时间,强制拉起下一个进程
                if ($runProcessMetaItem->pid > 0  && \Swoole\Process::kill($runProcessMetaItem->pid, 0) &&  time() > ($timeOut + $startTime)) {
                    $isNext = true;
                    break;
                }

                // 寄存都已退出进程
                if ($runProcessMetaItem->pid > 0 && !\Swoole\Process::kill($runProcessMetaItem->pid, 0)) {
                    $exitProcess[] = $runProcessMetaItem;
                }
            }

            if (!isset($isNext)) {
                // 所有的进程都已退出，则需要重新拉起进程
                if (isset($exitProcess) && is_array($exitProcess) && count($this->runProcessMetaPool) == count($exitProcess)) {
                    $isNext = true;
                    // 清空进程元信息池
                    $this->runProcessMetaPool = [];
                } else {
                    System::sleep(0.3);
                    $isNext = false;
                }
            }

            $this->debug("【{$this->cronName}】进入isNextHandle()方法，runProcessMetaPool的Size=".count($this->runProcessMetaPool));
            if ($isNext) {
                $this->debug("【{$this->cronName}】暂时未达到最大的并发进程数={$this->concurrent}，此时满足时间点触发，继续拉起新进程，isNextHandle() return true");
            } else {
                $this->debug("【{$this->cronName}】已达到最大的并发进程数={$this->concurrent}，将禁止继续拉起进程，isNextHandle() return false");
            }

        } else {
            $this->debug("【{$this->cronName}】暂时未达到最大的并发进程，满足时间点触发，继续拉起新进程，isNextHandle() return true");
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
        foreach ($args as $argvName=>$argvValue) {
            if (is_string($argvName)) {
                if (str_contains($argvValue, ' ')) {
                    $argvOptions[] = "--{$argvName}='{$argvValue}'";
                } else {
                    $argvOptions[] = "--{$argvName}={$argvValue}";
                }
            } else if (is_numeric($argvName)) {
                $argvOptions[] = $argvValue;
            }
        }

        if (!empty($argvOptions)) {
            $args = $argvOptions;
        }
        return implode(' ', $args);
    }

    /**
     * 获取正在运行的fork进程列表
     *
     * @return array
     */
    public function getRunningForkProcess(): array
    {
        $runningItemList = [];
        /**
         * @var RunProcessMetaDtoWorker $runProcessMetaItem
         */
        foreach ($this->runProcessMetaPool as $runProcessMetaItem) {
            $pid = $runProcessMetaItem->pid;
            if ($pid > 0 && \Swoole\Process::kill($pid, 0)) {
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
    public function gcExitProcess()
    {
        $itemList = [];
        /**
         * @var RunProcessMetaDtoWorker $runProcessMetaItem
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
                        } else {
                            // 进程已正常
                            $runProcessMetaItem->pid = $pid;
                            $runProcessMetaItem->check_total_count++;
                            $itemList[] = $runProcessMetaItem;
                        }
                    } else {
                        // 检测10次后，依然没有pid文件生成，说明此时脚本的启动已经可能异常了
                        if ($runProcessMetaItem->check_pid_not_exist_count < 10) {
                            $runProcessMetaItem->check_total_count++;
                            $runProcessMetaItem->check_pid_not_exist_count++;
                            $itemList[] = $runProcessMetaItem;
                        }
                    }
                } else {
                    if (\Swoole\Process::kill($pid, 0)) {
                        $runProcessMetaItem->check_total_count++;
                        $itemList[] = $runProcessMetaItem;
                    }
                }
            }

            $this->runProcessMetaPool = $itemList;

            $this->debug("【{$this->cronName}】定时检查每个cron任务中Runner运行中Fork进程数量=".count($this->runProcessMetaPool));
        }
        return $itemList;
    }


    /**
     * @return void
     */
    public function registerTickOfCheckRunningProcess()
    {
        static::$checkRunningTickerChannel = goTick($this->checkTickerTime * 1000, function () {
            $this->debug("定时检查cron服务总的进行中的定时Runner任务数量=".count(static::$instances));
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
                     * @var RunProcessMetaDtoWorker $runProcessMetaDto
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
    }

    protected static function debug(string $info)
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