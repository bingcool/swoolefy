<?php

namespace Test\WorkerDaemon;

use Swoolefy\Core\Application;

class PipeWorker extends \Swoolefy\Worker\WorkerProcess
{

    public function run()
    {
        var_dump('CID='.\Co::getCid());
        var_dump('PipeWorker');
        sleep(10);


//        $db = Application::getApp()->get('db');
//        $result = $db->createCommand('select * from tbl_users limit 1')->queryAll();
//        dump($result);

        //$this->reboot();

        if($this->isWorker0()) {
            $this->notifyMasterCreateDynamicProcess($this->getProcessName(), 1);
        }
    }

    public function onHandleException(\Throwable $throwable, array $context = [])
    {
        parent::onHandleException($throwable, $context);
        var_dump($throwable->getMessage());
    }
}