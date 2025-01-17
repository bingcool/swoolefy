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
use Swoolefy\Core\CommandRunner;
use Swoolefy\Core\Crontab\CrontabManager;
use Swoolefy\Core\Schedule\FilterDto;

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
            foreach($taskList as $task) {
                $forkType = $task['fork_type'] ?? $this->forkType;
                $isNewAddFlag = $this->isNewAddTask($task);
                if ($isNewAddFlag) {
                    try {
                        CrontabManager::getInstance()->addRule($task['cron_name'], $task['cron_expression'], function ($cron_name, $expression) use($task, $forkType) {
                            if (isset($task['filters']) && !empty($task['filters'])) {
                                foreach ($task['filters'] as $filter) {
                                    if ($filter instanceof FilterDto) {
                                        $canDue = call_user_func($filter->getFn(), $filter->getParams());
                                        if ($canDue == false) {
                                            return;
                                        }
                                    }
                                }
                            }

                            if (isset($task['call_fns']) && !empty($task['call_fns'])) {
                                foreach ($task['call_fns'] as $fnItems) {
                                    list($handler, $action) = $fnItems;
                                    (new $handler)->$action($task);
                                }
                            }

                            $runner = CommandRunner::getInstance($cron_name,5);
                            // 确保任务不会重叠运行.如果上一次任务仍在运行，则跳过本次执行
                            if (isset($task['with_block_lapping']) && $task['with_block_lapping'] == true) {
                                $runningForkProcess = $runner->getRunningForkProcess();
                                if (!empty($runningForkProcess)) {
                                    var_dump('with_time='.date('Y-m-d H:i:s'));
                                    return;
                                }
                            }

                            $this->randSleepTime($task['cron_expression']);
                            try {
                                $argv     = $task['argv'] ?? [];
                                $extend   = $task['extend'] ?? [];
                                // 不限制并发处理
                                if ($runner->isNextHandle(false)) {
                                    if ($forkType == self::FORK_TYPE_PROC_OPEN) {
                                        $runner->procOpen($task['exec_bin_file'], $task['exec_script'], $argv, function ($pipe0, $pipe1, $pipe2, $statusProperty) use($task) {
                                            $this->receiveCallBack($pipe0, $pipe1, $pipe2, $statusProperty, $task);
                                        }, $extend);
                                    }else {
                                        $runner->exec($task['exec_bin_file'], $task['exec_script'], $argv, true);
                                    }
                                }
                            }catch (\Throwable $exception) {
                                $this->onHandleException($exception, $task);
                            }
                        });
                    }catch (\Throwable $throwable) {
                        $this->onHandleException($throwable, $task);
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
     * @param $statusProperty
     * @param $task
     */
    protected function receiveCallBack($pipe0, $pipe1, $pipe2, $statusProperty, $task)
    {

    }
}