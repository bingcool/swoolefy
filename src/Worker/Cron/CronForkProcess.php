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
use Swoolefy\Core\Crontab\CrontabManager;
use Swoolefy\Core\Log\LogManager;
use Swoolefy\Core\Schedule\BetweenFilterDto;
use Swoolefy\Core\Schedule\DynamicCallFn;
use Swoolefy\Core\Schedule\ScheduleEvent;
use Swoolefy\Core\Schedule\SkipFilterDto;
use Swoolefy\Script\AbstractKernel;

class CronForkProcess extends CronProcess
{

    const FORK_TYPE_EXEC = 'exec';

    const FORK_TYPE_PROC_OPEN = 'proc_open';

    /**
     * @var string
     */
    protected $forkType = self::FORK_TYPE_PROC_OPEN;

    /**
     * onInit
     * @return void
     */
    public function onInit()
    {
        parent::onInit();
    }

    /**
     * run
     */
    public function run()
    {
        try {
            parent::run();
            $this->runCronTask();
        } catch (\Throwable $throwable) {
            $context = [
                'file' => $throwable->getFile(),
                'line' => $throwable->getLine(),
                'message' => $throwable->getMessage(),
                'code' => $throwable->getCode(),
                "reboot_count" => $this->getRebootCount(),
                'trace' => $throwable->getTraceAsString(),
            ];
            parent::onHandleException($throwable, $context);
            sleep(2);
            $this->reboot();
        }
    }

    /**
     * @param array $taskList
     * @return void
     */
    protected function registerCronTask(array $taskList)
    {
        if(!empty($taskList)) {
            foreach($taskList as $taskItem) {
                $this->runRegisterCronTask($taskItem);
            }
        }

        // 解除已暂停的定时任务
        $this->unregisterCronTask($taskList);
        // 重新注册meta信息有变动的定时任务
        $this->reRegisterCronTaskOfChangeMeta($taskList);
    }

    /**
     * @param array $taskItem
     * @param bool $registerAgain // 是否重新注册任务
     * @return void
     */
    protected function runRegisterCronTask(array $taskItem, bool $registerAgain = false)
    {
        /**
         * @var ScheduleEvent $scheduleTask
         */
        $scheduleTask = ScheduleEvent::load($taskItem);
        // 调用其他类型语言脚本(python)可以不必设置此参数,直接置空
        if (!isset($taskItem['run_type'])) {
            $scheduleTask->run_type = '';
        }

        if(!empty($scheduleTask->fork_type)) {
            $forkType = $scheduleTask->fork_type;
        }else {
            $forkType = CronForkProcess::FORK_TYPE_PROC_OPEN;
        }

        if (!$registerAgain) {
            $isNewAddFlag = $this->isNewAddTask($scheduleTask->cron_name);
        }else {
            $isNewAddFlag = true;
        }

        if ($isNewAddFlag) {
            try {
                CrontabManager::getInstance()->addRule($scheduleTask->cron_name, $scheduleTask->cron_expression, function () use($scheduleTask, $forkType) {
                    $scheduleTaskItems = $scheduleTask->toArray();
                    $logger = LogManager::getInstance()->getLogger(LogManager::CRON_FORK_LOG);
                    $runner = CronForkRunner::getInstance(md5($scheduleTask->cron_name),5, $scheduleTask->cron_name);

                    if (!empty($scheduleTask->cron_between)) {
                        $cronBetweenArr = $scheduleTask->cron_between;
                        if (is_array($cronBetweenArr) && count($cronBetweenArr) == 2) {
                            $cronBetweenArr = [[$cronBetweenArr[0], $cronBetweenArr[1]]];
                        }
                        foreach ($cronBetweenArr as $cronBetween) {
                            if (is_array($cronBetween) && count($cronBetween) == 2) {
                                $cronBetween = $scheduleTask->parseBetweenTime($cronBetween[0], $cronBetween[1]);
                                if (empty($cronBetween)) {
                                    $msg = "【{$scheduleTask->cron_name}】配置项cron_between格式错误, time=".date('Y-m-d H:i:s');
                                    $logger->addInfo($msg, false, $scheduleTaskItems);
                                    $this->debug($msg);
                                    return;
                                }
                                $canDue = (new BetweenFilterDto())->filter($cronBetween);
                                if ($canDue == false) {
                                    $msg = "【{$scheduleTask->cron_name}】当前不在设定的允许between时间段内，不能执行任务, time=".date('Y-m-d H:i:s');
                                    $logger->addInfo($msg, false, $scheduleTaskItems);
                                    $this->debug($msg);
                                    return;
                                }
                            }else {
                                $msg = "【{$scheduleTask->cron_name}】配置项cron_between格式错误, time=".date('Y-m-d H:i:s');
                                $logger->addInfo($msg, false, $scheduleTaskItems);
                                $this->debug($msg);
                                return;
                            }
                        }
                    }

                    if (!empty($scheduleTask->cron_skip)) {
                        $cronSkipArr = $scheduleTask->cron_skip;
                        if (is_array($cronSkipArr) && count($cronSkipArr) == 2) {
                            $cronSkipArr = [[$cronSkipArr[0], $cronSkipArr[1]]];
                        }
                        foreach ($cronSkipArr as $cronSkip) {
                            if (is_array($cronSkip) && count($cronSkip) == 2) {
                                $cronSkip = $scheduleTask->parseBetweenTime($cronSkip[0], $cronSkip[1]);
                                if (empty($cronSkip)) {
                                    $msg = "【{$scheduleTask->cron_name}】配置项cron_skip格式错误, time=".date('Y-m-d H:i:s');
                                    $logger->addInfo($msg, false, $scheduleTaskItems);
                                    $this->debug($msg);
                                    return;
                                }
                                $canDue = (new SkipFilterDto())->filter($cronSkip);
                                if ($canDue == false) {
                                    $msg = "【{$scheduleTask->cron_name}】当前时间任务在skip时间段内,不能执行任务，time=".date('Y-m-d H:i:s');
                                    $logger->addInfo($msg, false, $scheduleTaskItems);
                                    $this->debug($msg);
                                    return;
                                }
                            }else {
                                $msg = "【{$scheduleTask->cron_name}】配置项cron_skip格式错误, time=".date('Y-m-d H:i:s');
                                $logger->addInfo($msg, false, $scheduleTaskItems);
                                $this->debug($msg);
                                return;
                            }
                        }
                    }

                    // !!!import swoolefy script run type
                    if ($this->isSwoolefyRunType($scheduleTask->run_type)) {
                        $scheduleModelValue = 'cron';
                        $scheduleModelOption = AbstractKernel::getScheduleModelOptionField();
                        // set schedule_model, cron_script_pid_file in extend array
                        $scheduleTask->extend[$scheduleModelOption] = $scheduleModelValue;
                        (new DynamicCallFn())->generatePidFile($scheduleTask);

                        // set schedule_model, cron_script_pid_file in argv array
                        $scheduleTask->argv['daemon'] = 1;
                        $scheduleTask->argv[$scheduleModelOption] = $scheduleModelValue;

                        // swoolefy run type,use proc_open will good
                        $forkType = CronForkProcess::FORK_TYPE_PROC_OPEN;
                    }

                    // 日志无需打印回调闭包函数
                    $scheduleTaskItems['fork_success_callback'] = $scheduleTaskItems['fork_fail_callback'] = '';
                    // 确保任务不会重叠运行.如果上一次任务仍在运行，则跳过本次执行
                    if (isset($scheduleTask->with_block_lapping) && $scheduleTask->with_block_lapping == true) {
                        $runningForkProcess = $runner->getRunningForkProcess();
                        if (!empty($runningForkProcess)) {
                            $msg = "【{$scheduleTask->cron_name}】with_block_lapping阻塞重叠中不执行下一轮, cron_expression={$scheduleTask->cron_expression}";
                            $logger->addInfo($msg, false, $scheduleTaskItems);
                            $this->debug($msg);
                            return;
                        }
                    }

                    $logger->addInfo("{$scheduleTask->cron_name}】cron_fork任务开始执行, cron_expression=".$scheduleTask->cron_expression, false, $scheduleTaskItems);
                    $this->randSleepTime($scheduleTask->cron_expression);
                    try {
                        $argv     = $scheduleTask->argv ?? [];
                        $extend   = $scheduleTask->extend ?? [];
                        // 限制并发处理
                        $isNextHandle = $runner->isNextHandle(true, 120);
                        if (!$isNextHandle) {
                            $logger->addInfo("{$scheduleTask->cron_name}】cron_fork任务达到最大限制并发数，禁止fork进程, cron_expression=".$scheduleTask->cron_expression, false, $scheduleTaskItems);
                        }
                        if ($isNextHandle) {
                            if ($forkType == self::FORK_TYPE_PROC_OPEN) {
                                $runner->procOpen($scheduleTask->exec_bin_file, $scheduleTask->exec_script, $argv, function ($pipe0, $pipe1, $pipe2, $statusProperty) use($scheduleTask) {
                                    $this->receiveCallBack($pipe0, $pipe1, $pipe2, $statusProperty, $scheduleTask);
                                }, $extend);
                            }else {
                                $output = '/dev/null';
                                if (!empty($scheduleTask->output)) {
                                    $output = $scheduleTask->output;
                                }
                                list($command, $execOutput, $returnCode, $pid) = $runner->exec($scheduleTask->exec_bin_file, $scheduleTask->exec_script, $argv, true, $output, true, $extend);

                                $msg = "{$scheduleTask->cron_name}】Exec进程执行结果command={$command},returnCode={$returnCode},pid={$pid}，time=".date('Y-m-d H:i:s');
                                $logger->addInfo($msg, false, $scheduleTaskItems);
                                $this->debug($msg);

                                \Swoole\Coroutine\System::sleep(0.1);
                                if ($returnCode == 0 || \Swoole\Process::kill($pid, 0)) {
                                    if (is_callable($scheduleTask->fork_success_callback)) {
                                        try {
                                            call_user_func($scheduleTask->fork_success_callback, $scheduleTask);
                                        }catch (\Throwable $throwable) {
                                            // 忽略异常
                                        }
                                    }
                                }
                            }
                            $logger->addInfo("{$scheduleTask->cron_name}】cron_fork任务fork进程成功, cron_expression=".$scheduleTask->cron_expression, false, $scheduleTaskItems);
                        }
                    }catch (\Throwable $exception) {
                        $logger->addInfo("{$scheduleTask->cron_name}】cron_fork进程失败, cron_expression=".$scheduleTask->cron_expression." error=".$exception->getMessage() , false, $scheduleTaskItems);
                        if (is_callable($scheduleTask->fork_fail_callback)) {
                            try {
                                call_user_func($scheduleTask->fork_fail_callback, $scheduleTask, $exception);
                            }catch (\Throwable $throwable) {
                                // 忽略异常
                            }
                        }
                        $this->onHandleException($exception, $scheduleTask->toArray());
                    }
                }, null,null, $scheduleTask->toArray());
            }catch (\Throwable $throwable) {
                if (is_callable($scheduleTask->fork_fail_callback)) {
                    try {
                        call_user_func($scheduleTask->fork_fail_callback, $scheduleTask, $throwable);
                    }catch (\Throwable $throwable) {
                        // 忽略异常
                    }
                }
                $this->onHandleException($throwable, $scheduleTask->toArray());
            }
        }
    }

    /**
     * @param string $cronExpression
     * @return  bool
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
        }else {
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
     *
     * @param string $runType
     * @return bool
     */
    protected function isSwoolefyRunType($runType)
    {
        return str_contains(strtolower($runType), ScheduleEvent::RUN_TYPE);
    }

    /**
     * @param string $msg
     * @return void
     */
    protected function debug(string $msg)
    {
        if (env('CRON_DEBUG')) {
            fmtPrintNote($msg);
        }
    }
}