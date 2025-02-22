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
use Swoolefy\Exception\SystemException;
use Swoolefy\Worker\Dto\CronLocalTaskMetaDto;

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
     * @var CronLocalTaskMetaDto
     */
    protected $cronLocalTaskMetaDto;

    /**
     * onInit
     * @return void
     */
    public function onInit()
    {
        $this->handleClass    = $this->getArgs()['handler_class'];
        if (empty($this->handleClass)) {
            throw new SystemException("handleClass is empty");
        }

        if (!is_subclass_of($this->handleClass, \Swoolefy\Core\Crontab\AbstractCronController::class)) {
            throw new SystemException("handleClass should be extend Swoolefy\Core\Crontab\AbstractCronController");
        }

        !$this->registerLogFlag && $this->registerLogComponents(2, $this->handleClass);
        parent::onInit();
        $this->cronName         = $this->getArgs()['cron_name'];
        $this->cronExpression   = $this->getArgs()['cron_expression'];
        $this->withBlockLapping = $this->getArgs()['with_block_lapping'] ?? $this->withBlockLapping;
        $this->runInBackground  = $this->getArgs()['run_in_background'] ?? $this->runInBackground;
        $this->cronLocalTaskMetaDto = new CronLocalTaskMetaDto();
        $this->cronLocalTaskMetaDto->cron_name = $this->cronName;
        $this->cronLocalTaskMetaDto->cron_expression = $this->cronExpression;
        $this->cronLocalTaskMetaDto->handler_class = $this->handleClass;
        $this->cronLocalTaskMetaDto->with_block_lapping = $this->withBlockLapping;
        $this->cronLocalTaskMetaDto->run_in_background = $this->runInBackground;
    }

    /**
     * run
     * @return void
     */
    public function run()
    {
        try {
            CrontabManager::getInstance()->addRule($this->cronName, $this->cronExpression, [$this->handleClass, 'doCronTask'],
                function (): bool {
                    $logger = LogManager::getInstance()->getLogger(LogManager::CRON_LOCAL_LOG);
                    // 上一个任务未执行完，下一个任务到来时不执行，返回false结束
                    if ($this->withBlockLapping && $this->handing) {
                        $logger->addInfo("【{$this->getProcessName()}】local本地进程定时任务还在处理中，暂时不再继续执行本轮定时任务，本轮定时任务结束");
                        fmtPrintNote("【{$this->getProcessName()}】进程定时任务还在处理中，暂时不再继续执行本轮定时任务，本轮定时任务结束");
                        return false;
                    }

                    if (!$this->isDue()) {
                        $logger->addInfo("【{$this->getProcessName()}】本地定时任务进程退出|重启中，暂时不再继续执行本轮定时任务，本轮定时任务结束");
                        fmtPrintNote("【{$this->getProcessName()}】定时任务进程退出|重启中，暂时不再继续执行本轮定时任务，本轮定时任务结束");
                        return false;
                    }
                    $logger->addInfo("【{$this->getProcessName()}】开始处理本地定时任务业务！");
                    $this->handing = true;
                    return true;
                },
                function () {
                    $logger = LogManager::getInstance()->getLogger(LogManager::CRON_LOCAL_LOG);
                    $this->handing = false;
                    $logger->addInfo("【{$this->getProcessName()}】结束并处理完成本地定时任务业务");
                    // 任务业务处理完，接收waitToExit=true的指令，进程退出
                    if ($this->waitToExit) {
                        $logger->addInfo("【{$this->getProcessName()}】本地定时任务已接收到退出指令，正在退出进程");
                        $this->exitNow($this->getPid(), 5);
                        return false;
                    }

                    // 定时任务处理完之后，判断达到一定时间，然后重启进程
                    if (is_numeric($this->lifeTime)) {
                        if ( (time() > $this->getStartTime() + $this->lifeTime) && $this->isDue()) {
                            $logger->addInfo("【{$this->getProcessName()}】本地定时任务已处理完，现达到一定存活时间[$this->lifeTime]，正在重启进程");
                            $this->reboot(5);
                        }
                    }
            });
        }catch (\Throwable $exception) {
            $this->onHandleException($exception, $this->getArgs());
        }
    }
}