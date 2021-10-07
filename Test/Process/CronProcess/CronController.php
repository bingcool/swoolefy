<?php
namespace Test\Process\CronProcess;

use Cron\CronExpression;
use Swoolefy\Core\Application;
use Swoolefy\Core\Crontab\AbstractCronController;

class CronController extends AbstractCronController {

    /**
     * @inheritDoc
     */
    public function doCronTask(CronExpression $cron)
    {
        $expression = $cron->getExpression();

        $redis = Application::getApp()->get('redis');
        $redis->set('key','key-id='.rand(1,1000));
        $keyValue = $redis->get('key');
        var_dump("This is Crontab process, keyValue={$keyValue}, expression={$expression}, class=".__CLASS__);

    }
}