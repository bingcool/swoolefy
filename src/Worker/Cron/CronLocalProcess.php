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
     * onInit
     * @return void
     */
    public function onInit()
    {
        parent::onInit();
        $this->cronName       = $this->getArgs()['cron_name'];
        $this->cronExpression = $this->getArgs()['cron_expression'];
        $this->handleClass    = $this->getArgs()['handler_class'];
    }

    /**
     * run
     * @return void
     */
    public function run()
    {
        try {
            CrontabManager::getInstance()->addRule($this->cronName, $this->cronExpression, [$this->handleClass,'doCronTask']);
        }catch (\Throwable $exception) {
            $this->onHandleException($exception, $this->getArgs());
        }
    }
}