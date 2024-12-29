<?php
namespace Test\WorkerDaemon;

class TestWorkerProcess extends \Swoolefy\Worker\AbstractWorkerProcess
{
    public function run()
    {
        sleep(10);
        echo "test WorkerProcess\n";
    }
}