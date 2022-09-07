<?php

namespace Test\WorkerDaemon;

class PipeWorker extends \Swoolefy\Worker\WorkerProcess
{

    public function run()
    {
        var_dump('PipeWorker');
    }
}