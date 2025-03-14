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
            // 启动执行一次
            $this->registerCronTask($taskList());
            // 定时拉取最新cron配置
            \Swoolefy\Core\Coroutine\Timer::tick(20 * 1000, function () use($taskList) {
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