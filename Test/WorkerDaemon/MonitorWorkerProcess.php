<?php

declare(strict_types=1);

namespace Test\WorkerDaemon;

class MonitorWorkerProcess extends \Swoolefy\Worker\AbstractWorkerProcess
{
    public function run()
    {
        sleep(10);
        echo "test WorkerProcess\n";
    }
}