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

use Swoolefy\Core\Crontab\CrontabManager;
use Swoolefy\Core\Log\LogManager;
use Swoolefy\Core\Schedule\ScheduleEvent;
use Swoolefy\Worker\AbstractWorkerProcess;
use Swoolefy\Worker\Dto\CronForkTaskMetaDto;
use Swoolefy\Worker\Dto\CronUrlTaskMetaDto;

class CronProcess extends AbstractWorkerProcess
{

    const EXEC_FORK_TYPE = 1;

    const EXEC_URL_TYPE = 2;
    /**
     * @var mixed
     */
    protected $taskList;

    /**
     * onInit
     * @return void
     */
    public function onInit()
    {
        parent::onInit();
        $this->taskList = $this->getArgs()['task_list'] ?? [];
    }

    /**
     * @return void
     */
    protected function runCronTask()
    {
        $taskList = $this->taskList;
        if ($taskList instanceof \Closure) {
            // 启动执行一次
            $this->registerCronTask($taskList());
            // 定时拉取最新cron配置
            \Swoolefy\Core\Coroutine\Timer::tick(20 * 1000, function () use($taskList) {
                $lastTaskList = $taskList();
                $this->registerCronTask($lastTaskList);
            });
        } else {
            $this->registerCronTask($taskList);
        }
    }

    /**
     * 解除已暂停的定时任务
     *
     * @param array $taskList
     * @param int $execType
     * @return void
     */
    protected function unregisterCronTask(array &$taskList, $execType = CronProcess::EXEC_FORK_TYPE)
    {
        // 剔除已暂停的计划任务
        $runCronTaskList = CrontabManager::getInstance()->getRunCronTaskList();
        if (!empty($runCronTaskList)) {
            $taskCronNameList    = array_column($taskList, 'cron_name');
            $taskCronNameKeyList = array_map(function ($item) {
                return md5($item);
            }, $taskCronNameList);

            $logger = LogManager::getInstance()->getLogger(LogManager::CRON_FORK_LOG);
            foreach ($runCronTaskList as $cronNameKey => $cronTask) {
                if (!in_array($cronNameKey, $taskCronNameKeyList)) {
                    // 删除已经暂停的计划任务
                    CrontabManager::getInstance()->removeCronTaskByName($cronTask['cron_name']);
                    // 删除已经暂停的计划任务对应的runner
                    CronForkRunner::removeRunner(md5($cronTask['cron_name']));
                    // 加载旧的cron任务Meta，寄存在cron运行时中，用于记录日志
                    $oldCronTask = $cronTask['extend'] ?? [];
                    if ($execType == CronProcess::EXEC_FORK_TYPE) {
                        $this->logCronTaskRuntime(ScheduleEvent::load($oldCronTask),"","[Remove Cron]任务停止或删除，定时任务已暂停");
                    } else if ($execType == CronProcess::EXEC_URL_TYPE) {
                        $this->logCronTaskRuntime(CronUrlTaskMetaDto::load($oldCronTask),"","[Remove Cron]任务停止或删除，定时任务已暂停");
                    }
                    $logger->info("Remove cron task 【{$cronTask['cron_name']}】 has stopped");
                    fmtPrintInfo("Remove cron task 【{$cronTask['cron_name']}】 has stopped");
                }
            }
        }
    }

    /**
     * db cron task Meta配置模式下的重新注册cron任务处理
     *
     * 重新注册cron_name不变，但是其他的meta信息有改变的，比如cron_expression有变动的
     *
     * @param array $taskList
     * @param int $execType
     * @return void
     */
    protected function reRegisterCronTaskOfChangeMeta(array &$taskList, $execType = CronProcess::EXEC_FORK_TYPE)
    {
        if (empty($taskList)) {
            return;
        }

        $firstItem = $taskList[0];

        // 配置是DB保存模式的才处理
        if (isset($firstItem['cron_meta_origin']) && $firstItem['cron_meta_origin'] != CronForkTaskMetaDto::CRON_META_ORIGIN_DB) {
            return;
        }

        $runCronTaskList = CrontabManager::getInstance()->getRunCronTaskList();
        if (!empty($runCronTaskList)) {
            $taskCronNameList    = array_column($taskList, 'cron_name');
            $taskCronNameKeyList = array_map(function ($item) {
                return md5($item);
            }, $taskCronNameList);

            $taskCronNameMap = array_column($taskList, null, 'cron_name');
            $logger = LogManager::getInstance()->getLogger(LogManager::CRON_FORK_LOG);
            foreach ($runCronTaskList as $cronNameKey => $cronTask) {
                if (in_array($cronNameKey, $taskCronNameKeyList)) {
                    $oldUpdatedAt = $cronTask['extend']['updated_at'] ?? "";
                    $newCronTask = $taskCronNameMap[$cronTask['cron_name']] ?? [];
                    $newUpdatedAt = $newCronTask['updated_at'] ?? "";
                    if (!empty($oldUpdatedAt) && !empty($newUpdatedAt) && $oldUpdatedAt != $newUpdatedAt) {
                        // 删除已经meta信息有变动的计划任务
                        CrontabManager::getInstance()->removeCronTaskByName($cronTask['cron_name']);
                        // 删除已经meta信息有变动的的计划任务对应的runner
                        CronForkRunner::removeRunner($cronNameKey);
                        // 重新注册cron任务
                        $this->runRegisterCronTask($newCronTask, true);
                        if ($execType == CronProcess::EXEC_FORK_TYPE) {
                            $this->logCronTaskRuntime(ScheduleEvent::load($newCronTask),"","[Re-register]任务配置有变动，已重新注册定时任务");
                        }else if ($execType == CronProcess::EXEC_URL_TYPE) {
                            $this->logCronTaskRuntime(CronUrlTaskMetaDto::load($newCronTask),"","[Re-register]任务配置有变动，已重新注册定时任务");
                        }
                        $logger->info("Re-register cron task 【{$cronTask['cron_name']}】 has meta changed");
                        fmtPrintInfo("Re-register cron task 【{$cronTask['cron_name']}】 has meta changed");
                    }
                }
            }
        }
    }

    /**
     * @param string $cronName
     * @return bool
     */
    protected function isNewAddTask(string $cronName)
    {
        $cronTask = CrontabManager::getInstance()->getCronTaskByName($cronName);
        if (!empty($cronTask) && is_array($cronTask)) {
            $timerId = $cronTask['timer_id'];
            if (!\Swoole\Timer::exists($timerId)) {
                $isNewAddFlag = true;
            }else {
                $isNewAddFlag = false;
            }
        }else {
            $isNewAddFlag = true;
        }

        return $isNewAddFlag;
    }

    /**
     * @inheritDoc
     * @return mixed
     */
    public function run()
    {

    }

    /**
     * @param array $taskItem
     * @param string $execBatchId
     * @param string $message
     * @return void
     */
    protected function logCronTaskRuntime(
        ScheduleEvent|CronUrlTaskMetaDto $scheduleTask,
        string $execBatchId,
        string $message,
        int $pid = 0,
    )
    {
        if (isset($scheduleTask->cron_task_id) && $scheduleTask->cron_task_id > 0 && !empty($scheduleTask->cron_db_log_class)) {
            /**
             * @var \Swoolefy\Worker\Cron\CronTaskInterface $logClass
             */
            $logClass = $scheduleTask->cron_db_log_class;
            try {
                (new $logClass)->logCronTaskRuntime($scheduleTask, $execBatchId, $message, $pid);
            }catch (\Throwable $e) {
                $errorMsg = "CronTaskInterface logCronTaskRuntime error: {$e->getMessage()}";
                $logger = LogManager::getInstance()->getLogger(LogManager::CRON_FORK_LOG);
                $logger->error($errorMsg);
                fmtPrintError($errorMsg);
            }
        }
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