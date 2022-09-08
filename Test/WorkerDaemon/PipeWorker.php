<?php

namespace Test\WorkerDaemon;

class PipeWorker extends \Swoolefy\Worker\WorkerProcess
{

    public function run()
    {
        var_dump($this->getCliParams('name'));
        var_dump('PipeWorker');
        sleep(10);

        $this->notifyMasterCreateDynamicProcess($this->getProcessName(), 1);
    }

    public function onHandleException(\Throwable $throwable, array $context = [])
    {
        parent::onHandleException($throwable, $context);
        var_dump($throwable->getMessage());
    }
}