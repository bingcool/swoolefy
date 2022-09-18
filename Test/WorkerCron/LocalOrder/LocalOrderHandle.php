<?php
namespace Test\WorkerCron\LocalOrder;

use Swoolefy\Core\Crontab\AbstractCronController;

class LocalOrderHandle extends AbstractCronController {

    public function doCronTask($cron, string $cron_name)
    {
        var_dump(date('Y-m-d H:i:s'));
    }
}