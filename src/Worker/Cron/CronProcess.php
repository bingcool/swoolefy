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
use Swoolefy\Worker\AbstractWorkerProcess;
use Swoolefy\Worker\Dto\CronForkTaskMetaDto;

class CronProcess extends AbstractWorkerProcess
{

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
            \Swoolefy\Core\Coroutine\Timer::tick(10 * 1000, function () use($taskList) {
                $lastTaskList = $taskList();
                $this->registerCronTask($lastTaskList);
            });
        }else {
            $this->registerCronTask($taskList);
        }
    }

    /**
     * 解除已暂停的定时任务
     *
     * @param array $taskList
     * @return void
     */
    protected function unregisterCronTask(array &$taskList)
    {
        // 为空不处理，相当于整个任务都暂停
        if (empty($taskList)) {
            return;
        }
        // 剔除已暂停的计划任务
        $runCronTaskList = CrontabManager::getInstance()->getRunCronTaskList();
        if(!empty($runCronTaskList)) {
            $taskCronNameList    = array_column($taskList, 'cron_name');
            $taskCronNameKeyList = array_map(function ($item) {
                return md5($item);
            }, $taskCronNameList);
            foreach($runCronTaskList as $cronNameKey => $cronTask) {
                if (!in_array($cronNameKey, $taskCronNameKeyList)) {
                    // 删除已经暂停的计划任务
                    CrontabManager::getInstance()->removeCronTaskByName($cronTask['cron_name']);
                    // 删除已经暂停的计划任务对应的runner
                    CronForkRunner::removeRunner(md5($cronTask['cron_name']));
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
     * @return void
     */
    protected function reRegisterCronTaskOfChangeMeta(array &$taskList)
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
        if(!empty($runCronTaskList)) {
            $taskCronNameList    = array_column($taskList, 'cron_name');
            $taskCronNameKeyList = array_map(function ($item) {
                return md5($item);
            }, $taskCronNameList);
            $taskCronNameMap     = array_column($taskList, null, 'cron_name');
            foreach($runCronTaskList as $cronNameKey => $cronTask) {
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
}