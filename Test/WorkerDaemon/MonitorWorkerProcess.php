<?php

namespace Test\WorkerDaemon;

use Swoolefy\Core\Application;
use Swoolefy\Core\Log\LogManager;

class MonitorWorkerProcess extends \Swoolefy\Worker\AbstractWorkerProcess
{

    public function run()
    {
        sleep(10);
        RunLog::info("MonitorWorkerProcess");
        //$this->notifyMasterCreateDynamicProcess('test-pipe-worker',1);
    }
}