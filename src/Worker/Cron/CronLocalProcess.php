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

class CronLocalProcess extends CronProcess
{
    /**
     * @var string
     */
    protected $cronName;

    /**
     * @var string
     */
    protected $cronExpression;

    /**
     * @var string
     */
    protected $handleClass;

    /**
     * 重新注册日志
     *
     * @var bool
     */
    protected $registerLogFlag = false;

    /**
     * onInit
     * @return void
     */
    public function onInit()
    {
        $this->handleClass    = $this->getArgs()['handler_class'];
        !$this->registerLogFlag && $this->registerLogComponents(2, $this->handleClass);
        parent::onInit();
        $this->cronName       = $this->getArgs()['cron_name'];
        $this->cronExpression = $this->getArgs()['cron_expression'];
        $this->withBlockLapping = $this->getArgs()['with_block_lapping'] ?? $this->withBlockLapping;
        $this->runInBackground = $this->getArgs()['run_in_background'] ?? $this->runInBackground;
    }

    /**
     * run
     * @return void
     */
    public function run()
    {
        try {
            CrontabManager::getInstance()->addRule($this->cronName, $this->cronExpression, [$this->handleClass,'doCronTask'],
                function (): bool {
                    // 上一个任务未执行完，下一个任务到来时不执行，返回false结束
                    if ($this->withBlockLapping && $this->handing) {
                        $this->fmtWriteInfo("【{$this->getProcessName()}】进程定时任务还在处理中，暂时不再处理下一个任务");
                        return false;
                    }

                    if (!$this->isDue()) {
                        $this->fmtWriteInfo("【{$this->getProcessName()}】定时任务进程退出|重启中，暂时不再处理任务");
                        return false;
                    }

                    $this->handing = true;
                    return true;
                },
                function () {
                    $this->handing = false;
                    // 任务业务处理完，接收waitToExit=true的指令，进程退出
                    if ($this->waitToExit) {
                        $this->exitNow($this->getPid(), 5);
                        return false;
                    }

                    // 定时任务处理完之后，判断达到一定时间，然后重启进程
                    if (is_numeric($this->lifeTime)) {
                        if ( (time() > $this->getStartTime() + $this->lifeTime) && $this->isDue()) {
                            $this->reboot(5);
                        }
                    }
            });
        }catch (\Throwable $exception) {
            $this->onHandleException($exception, $this->getArgs());
        }
    }
}