<?php

namespace Test\WorkerDaemon;

use Swoolefy\Core\Application;

class PipeWorkerProcess extends \Swoolefy\Worker\AbstractWorkerProcess
{
    public function run()
    {
        while (1) {
            var_dump('CID='.\Co::getCid());
            var_dump('PipeWorker');
            sleep(5);
        }
//        $db = Application::getApp()->get('db');
//        $result = $db->createCommand('select * from tbl_users limit 1')->queryAll();
//        dump($result);

//        \Swoole\Coroutine::create(function () {
//            (new \Swoolefy\Core\EventApp)->registerApp(function (EventController $eventApp)  {
//                var_dump('mmmmmmmmmmmmmmmmmmmmmmmmm');
//            });
//        });

        Application::getApp()->get('log')->addInfo('pllllllllllll');

//        if($this->isWorker0()) {
//            $this->notifyMasterCreateDynamicProcess($this->getProcessName(), 1);
//        }

//        if($this->isWorker0()) {
//            $this->reboot();
//        }
    }

    public function onHandleException(\Throwable $throwable, array $context = [])
    {
        parent::onHandleException($throwable, $context);
        var_dump($throwable->getMessage());
    }
}