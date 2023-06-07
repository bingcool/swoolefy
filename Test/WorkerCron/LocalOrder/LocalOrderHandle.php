<?php
namespace Test\WorkerCron\LocalOrder;

use Swoolefy\Core\Crontab\AbstractCronController;

class LocalOrderHandle extends AbstractCronController {

    public function doCronTask($cron, string $cronName)
    {
        var_dump(date('Y-m-d H:i:s'));
        var_dump($cronName);
    }
}