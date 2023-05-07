<?php

namespace Test\WorkerDaemon;

use Swoolefy\Core\Application;

class MonitorWorkerProcess extends \Swoolefy\Worker\AbstractWorkerProcess
{

    public function run()
    {
        sleep(10);
        $this->notifyMasterCreateDynamicProcess('test-pipe-worker',1);
    }
}