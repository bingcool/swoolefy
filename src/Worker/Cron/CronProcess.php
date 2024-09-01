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
            // 马上执行一次
            $this->registerCronTask($taskList());
            // 定时拉取最新cron配置
            \Swoolefy\Core\Coroutine\Timer::tick(10 * 1000, function () use($taskList) {
                $taskListNew = $taskList();
                $this->registerCronTask($taskListNew);
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
        // 剔除已暂停的计划任务
        $cronMetaList = CrontabManager::getInstance()->getCronTaskByName();
        if(!empty($cronMetaList)) {
            $taskCronNameList    = array_column($taskList, 'cron_name');
            $taskCronNameKeyList = array_map(function ($item) {
                return md5($item);
            }, $taskCronNameList);
            foreach($cronMetaList as $cronNameKey => $cronMeta) {
                if (!in_array($cronNameKey, $taskCronNameKeyList)) {
                    // 删除已经暂停的计划任务
                    $timerId = $cronMeta['timer_id'];
                    if (\Swoole\Timer::exists($timerId)) {
                        \Swoole\Timer::clear($timerId);
                        CrontabManager::getInstance()->removeCronTaskByName($cronMeta['cron_name']);
                    }
                }
            }
        }
    }

    /**
     * @param array $task
     * @return bool
     */
    protected function isNewAddTask(array $task)
    {
        $cronMeta = CrontabManager::getInstance()->getCronTaskByName($task['cron_name']);
        if (!empty($cronMeta) && is_array($cronMeta)) {
            $timerId = $cronMeta['timer_id'];
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