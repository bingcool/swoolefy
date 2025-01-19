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
use Swoolefy\Core\Schedule\FilterDto;
use Swoolefy\Core\Schedule\ScheduleEvent;

class CronForkProcess extends CronProcess
{

    const FORK_TYPE_EXEC = 'exec';

    const FORK_TYPE_PROC_OPEN = 'proc_open';

    /**
     * @var string
     */
    protected $forkType = self::FORK_TYPE_PROC_OPEN;

    /**
     * @var array
     */
    protected $params = [];

    /**
     * onInit
     * @return void
     */
    public function onInit()
    {
        parent::onInit();
        $this->params   = $this->getArgs()['params'] ?? [];
    }

    /**
     * run
     */
    public function run()
    {
        parent::run();
        $this->runCronTask();
    }

    /**
     * @param array $taskList
     * @return void
     */
    protected function registerCronTask(array $taskList)
    {
        if(!empty($taskList)) {
            foreach($taskList as $taskItem) {
                /**
                 * @var ScheduleEvent $scheduleTask
                 */
                $scheduleTask = ScheduleEvent::load($taskItem);
                if(!empty($scheduleTask->fork_type)) {
                    $forkType = $this->forkType;
                }else {
                    $forkType = CronForkProcess::FORK_TYPE_PROC_OPEN;
                }
                $isNewAddFlag = $this->isNewAddTask($scheduleTask->cron_name);
                if ($isNewAddFlag) {
                    try {
                        CrontabManager::getInstance()->addRule($scheduleTask->cron_name, $scheduleTask->cron_expression, function () use($scheduleTask, $forkType) {
                            if (!empty($scheduleTask->filters)) {
                                foreach ($scheduleTask->filters as $filter) {
                                    if ($filter instanceof FilterDto) {
                                        $canDue = call_user_func($filter->getFn(), $filter->getParams());
                                        if ($canDue == false) {
                                            return;
                                        }
                                    }
                                }
                            }

                            if (!empty($scheduleTask->call_fns)) {
                                foreach ($scheduleTask->call_fns as $fnItems) {
                                    list($handler, $action) = $fnItems;
                                    (new $handler)->$action($scheduleTask);
                                }
                            }

                            $runner = CronForkRunner::getInstance(md5($scheduleTask->cron_name),5);
                            // 确保任务不会重叠运行.如果上一次任务仍在运行，则跳过本次执行
                            if (isset($scheduleTask->with_block_lapping) && $scheduleTask->with_block_lapping == true) {
                                $runningForkProcess = $runner->getRunningForkProcess();
                                if (!empty($runningForkProcess)) {
                                    if (env('CRON_DEBUG')) {
                                        fmtPrintNote('with_block_lapping阻塞重叠中不执行下一轮，time='.date('Y-m-d H:i:s'));
                                    }
                                    return;
                                }
                            }

                            $this->randSleepTime($scheduleTask->cron_expression);
                            try {
                                $argv     = $scheduleTask->argv ?? [];
                                $extend   = $scheduleTask->extend ?? [];
                                // 限制并发处理
                                if ($runner->isNextHandle(true, 120)) {
                                    if ($forkType == self::FORK_TYPE_PROC_OPEN) {
                                        $runner->procOpen($scheduleTask->exec_bin_file, $scheduleTask->exec_script, $argv, function ($pipe0, $pipe1, $pipe2, $statusProperty) use($scheduleTask) {
                                            $this->receiveCallBack($pipe0, $pipe1, $pipe2, $statusProperty, $scheduleTask);
                                        }, $extend);
                                    }else {
                                        $output = '/dev/null';
                                        if (!empty($scheduleTask->output)) {
                                            $output = $scheduleTask->output;
                                        }
                                        $runner->exec($scheduleTask->exec_bin_file, $scheduleTask->exec_script, $argv, true,$output, true, $extend);
                                    }
                                }
                            }catch (\Throwable $exception) {
                                $this->onHandleException($exception, $scheduleTask->toArray());
                            }
                        });
                    }catch (\Throwable $throwable) {
                        $this->onHandleException($throwable, $scheduleTask->toArray());
                    }
                }
            }

            // 解除已暂停的定时任务
            $this->unregisterCronTask($taskList);
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
     * @param ScheduleEvent $scheduleTask
     * @param $task
     */
    protected function receiveCallBack($pipe0, $pipe1, $pipe2, $statusProperty, ScheduleEvent $scheduleTask)
    {

    }
}