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

use Swoole\Coroutine\System;
use Swoolefy\Core\Log\LogManager;
use Swoolefy\Core\Schedule\DynamicCallFn;
use Swoolefy\Core\Schedule\ScheduleEvent;
use Swoolefy\Script\AbstractKernel;

/**
 * Shell / swoolefy script Cron Worker。
 *
 * 调度只走父类 CronManager（Polling + Diff + one-shot Timer + Guard）。
 * 执行走本类 executeCronSnapshot()：按冻结 Snapshot 拉起 CronForkRunner
 *（proc_open / exec），记录子进程 PID，异常隔离后返回 FAILED。
 *
 * 本类不再向 CrontabManager addRule。run() 只调用 runCronTask()。
 *
 * @see CronProcess::runCronTask()
 * @see ShellExecutor
 * @see CronForkRunner
 */
class CronForkProcess extends CronProcess
{

    /** 使用 php exec() 拉起（备选，swoolefy script 强制 proc_open）。 */
    const FORK_TYPE_EXEC = 'exec';

    /** 默认：proc_open，可拿到管道与 PID。 */
    const FORK_TYPE_PROC_OPEN = 'proc_open';

    /**
     * onInit
     * @return void
     */
    public function onInit()
    {
        parent::onInit();
    }

    /**
     * 使用生产引擎调度，执行仍走本类的 fork / swoolefy script 逻辑。
     */
    protected function createCronExecutor(): CronExecutorInterface
    {
        return new ShellExecutor(fn (ExecutionSnapshot $snapshot): ExecutionResult => $this->executeCronSnapshot($snapshot));
    }

    /**
     * 按 Execution Snapshot 拉起 Shell / swoolefy script。
     *
     * 使用冻结定义，Config Update 不影响本轮 command。异常在此捕获，不拖垮 Worker。
     */
    protected function executeCronSnapshot(ExecutionSnapshot $snapshot): ExecutionResult
    {
        $scheduleTask = $snapshot->definition->toLogDto();
        if (!$scheduleTask instanceof ScheduleEvent) {
            return ExecutionResult::failed('Shell 任务定义无法转为 ScheduleEvent');
        }

        $execBatchId = $snapshot->execBatchId;
        $scheduleTaskItems = $scheduleTask->toArray();
        $logger = LogManager::getInstance()->getLogger(LogManager::CRON_FORK_LOG);
        $runner = CronForkRunner::getInstance(md5($scheduleTask->cron_name), 5, $scheduleTask->cron_name);

        try {
            if (!empty($scheduleTask->fork_type)) {
                $forkType = $scheduleTask->fork_type;
            } else {
                $forkType = CronForkProcess::FORK_TYPE_PROC_OPEN;
            }

            if ($this->isSwoolefyRunType($scheduleTask->run_type)) {
                $scheduleModelValue = 'cron';
                $scheduleModelOption = AbstractKernel::getScheduleModelOptionField();
                $scheduleTask->extend[$scheduleModelOption] = $scheduleModelValue;
                (new DynamicCallFn())->generatePidFile($scheduleTask);
                $scheduleTask->argv['daemon'] = 1;
                $scheduleTask->argv[$scheduleModelOption] = $scheduleModelValue;
                $forkType = CronForkProcess::FORK_TYPE_PROC_OPEN;
            }

            $this->randSleepTime($scheduleTask->cron_expression);
            $argv = $scheduleTask->argv ?? [];
            $extend = $scheduleTask->extend ?? [];
            $isNextHandle = $runner->isNextHandle(true, 120);
            if (!$isNextHandle) {
                $logger?->addInfo("【{$scheduleTask->cron_name}】cron_fork任务达到最大限制并发数，禁止fork进程", false, $scheduleTaskItems);
                return ExecutionResult::failed('达到最大限制并发数，禁止 fork');
            }

            if ($forkType == self::FORK_TYPE_PROC_OPEN) {
                $procResult = $runner->procOpen($scheduleTask->exec_bin_file, $scheduleTask->exec_script, $argv, function ($pipe0, $pipe1, $pipe2, $statusProperty) use ($scheduleTask, $execBatchId) {
                    $statusProperty['exec_batch_id'] = $execBatchId;
                    $this->receiveCallBack($pipe0, $pipe1, $pipe2, $statusProperty, $scheduleTask);
                }, $extend);

                return $this->buildProcOpenExecutionResult($procResult, $scheduleTask->cron_name);
            }

            $output = !empty($scheduleTask->output) ? $scheduleTask->output : '/dev/null';
            list($command, $execOutput, $returnCode, $pid) = $runner->exec(
                $scheduleTask->exec_bin_file,
                $scheduleTask->exec_script,
                $argv,
                true,
                $output,
                true,
                $extend
            );
            $pid = (int) $pid;
            if ($returnCode == 0 || ($pid > 0 && \Swoole\Process::kill($pid, 0))) {
                if (is_callable($scheduleTask->fork_success_callback)) {
                    try {
                        call_user_func($scheduleTask->fork_success_callback, $scheduleTask);
                    } catch (\Throwable) {
                    }
                }
                return ExecutionResult::success(
                    "Exec command={$command},returnCode={$returnCode},pid={$pid}",
                    $pid,
                    (int) $returnCode,
                );
            }

            return ExecutionResult::failed(
                "Exec command={$command},returnCode={$returnCode},pid={$pid}",
                $pid,
                (int) $returnCode,
            );
        } catch (\Throwable $exception) {
            if (is_callable($scheduleTask->fork_fail_callback)) {
                try {
                    call_user_func($scheduleTask->fork_fail_callback, $scheduleTask, $exception);
                } catch (\Throwable) {
                }
            }
            $this->onHandleException($exception, $scheduleTask->toArray());
            return ExecutionResult::failed($exception->getMessage());
        }
    }

    /**
     * 启动生产引擎。Worker 级异常才 reboot；单个 Job 异常已在 Executor 内隔离。
     */
    public function run()
    {
        try {
            parent::run();
            $this->runCronTask();
        } catch (\Throwable $throwable) {
            $contextData = [
                'file' => $throwable->getFile(),
                'line' => $throwable->getLine(),
                'message' => $throwable->getMessage(),
                'code' => $throwable->getCode(),
                "reboot_count" => $this->getRebootCount(),
                'trace' => $throwable->getTraceAsString(),
            ];
            parent::onHandleException($throwable, $contextData);
            sleep(2);
            $this->reboot();
        }
    }

    /**
     * 分钟级 Cron（首字段为 * 或星号/N）启动前随机 sleep，降低整点惊群。
     * 秒级 Interval（纯数字）不 sleep。
     *
     * @param string $cronExpression
     * @return bool
     */
    protected function randSleepTime($cronExpression)
    {
        if (is_numeric($cronExpression)) {
            return true;
        }

        $todo = false;
        $expressionArr = explode(' ', trim($cronExpression));
        $firstItem = $expressionArr[0];
        if ($firstItem == '*') {
            $todo = true;
        } else {
            $firstItemArr = explode('/', $firstItem);
            if (isset($firstItemArr[1]) && is_numeric($firstItemArr[1])) {
                $todo = true;
            }
        }
        if ($todo) {
            $randNumArr = [0.2, 0.5, 0.8, 1.0, 1.5, 1.8, 2.0];
            $index = array_rand($randNumArr);
            $sleepTime = $randNumArr[$index] ?? 0.2;
            System::sleep($sleepTime);
        }

        return true;
    }

    /**
     * receive cli process return CallBack handle
     *
     * @param $pipe0
     * @param $pipe1
     * @param $pipe2
     * @param $statusProperty
     * @param ScheduleEvent $scheduleTask
     * @param $task
     */
    protected function receiveCallBack($pipe0, $pipe1, $pipe2, $statusProperty, ScheduleEvent $scheduleTask)
    {
        if (isset($statusProperty['pid']) && $statusProperty['pid'] > 0) {
            $this->logCronTaskRuntime(
                $scheduleTask,
                $statusProperty['exec_batch_id'] ?? '',
                "PROC_OPEN 拉起脚本的进程PID={$statusProperty['pid']}",
                $statusProperty['pid']
            );
        }
        // fork Process success callback handing
        if (is_callable($scheduleTask->fork_success_callback)) {
            try {
                call_user_func($scheduleTask->fork_success_callback, $scheduleTask);
            }catch (\Throwable $throwable) {
                // ignore exception
            }
        }
    }

    /**
     * 是否 swoolefy script 运行类型（需补 daemon / schedule_model / pid 文件）。
     *
     * @param string $runType
     * @return bool
     */
    protected function isSwoolefyRunType($runType)
    {
        return str_contains(strtolower($runType), ScheduleEvent::RUN_TYPE);
    }

    /**
     * 把 proc_open 的真实终态映射成 ExecutionResult，禁止“仅拉起成功就 SUCCESS”。
     *
     * @param array<string, mixed> $result
     */
    protected function buildProcOpenExecutionResult(array $result, string $cronName): ExecutionResult
    {
        $pid = (int) ($result['pid'] ?? 0);
        $hasExitCode = array_key_exists('exit_code', $result) && $result['exit_code'] !== null && $result['exit_code'] !== '';
        $exitCode = $hasExitCode ? (int) $result['exit_code'] : null;
        $signaled = !empty($result['signaled']);
        $termSignal = (int) ($result['term_signal'] ?? 0);
        $tailMessage = $this->buildProcOpenTailMessage($result);

        if ($pid <= 0) {
            return ExecutionResult::failed(
                "cron_fork proc_open 拉起异常(pid={$pid},exitCode={$exitCode}{$tailMessage})",
                $pid,
                $exitCode,
            );
        }

        if ($exitCode === 0) {
            return ExecutionResult::success(
                "cron_fork proc_open 执行成功(pid={$pid},exitCode=0)",
                $pid,
                0,
            );
        }

        if ($signaled || $termSignal > 0) {
            return ExecutionResult::failed(
                "cron_fork proc_open 结束(signal={$termSignal},pid={$pid},exitCode={$exitCode}{$tailMessage})",
                $pid,
                $exitCode,
            );
        }

        return ExecutionResult::failed(
            "cron_fork proc_open 执行失败(pid={$pid},exitCode={$exitCode}{$tailMessage})",
            $pid,
            $exitCode,
        );
    }

    /**
     * 优先使用 stderr 的尾部，避免把大段输出直接写进 message。
     *
     * @param array<string, mixed> $result
     */
    private function buildProcOpenTailMessage(array $result): string
    {
        $stderr = trim((string) ($result['stderr'] ?? ''));
        $stdout = trim((string) ($result['stdout'] ?? ''));
        $source = '';
        $tail = '';
        if ($stderr !== '') {
            $source = 'stderr';
            $tail = $stderr;
        } elseif ($stdout !== '') {
            $source = 'stdout';
            $tail = $stdout;
        }

        if ($tail === '') {
            return '';
        }

        $isTruncated = $source === 'stderr' ? !empty($result['stderr_truncated']) : !empty($result['stdout_truncated']);
        if (strlen($tail) > 200) {
            $tail = substr($tail, -200);
            $isTruncated = true;
        }

        $tail = preg_replace('/\s+/', ' ', $tail) ?? $tail;
        $tail = trim($tail);
        if ($tail === '') {
            return '';
        }

        $truncateNote = $isTruncated ? ', truncated=1' : '';
        return ", tailSource={$source}{$truncateNote}, tail={$tail}";
    }
}