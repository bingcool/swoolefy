<?php

namespace Test\WorkerDaemon;

use Swoolefy\Core\Application;
use Swoolefy\Core\EventController;
use Swoolefy\Core\Log\LogManager;

class PipeWorkerProcess extends \Swoolefy\Worker\AbstractWorkerProcess
{
    public function run()
    {
        //Application::getApp()->get('log')->addInfo('pllllllllllll');
        while (1) {
            if ($this->isExiting()) {
                sleep(1);
                continue;
            }

//            LogManager::getInstance()->getLogger('log')->info('kkkkkkkkkkkkkkkk');
//            var_dump('CID='.\Swoole\Coroutine::getCid());
//            var_dump('PipeWorker');
            $a = 1;
            $b = 2;
            $c = 3;
            goApp(function ($a, $b) use($c) {
                goApp(function () use($a, $b) {
                    goApp(function () use($a, $b) {
                        var_dump($a, $b);
                    });
                });
            }, $a, $b);

            sleep(10);
            var_dump("gggggggggggggggggggggggggg");
        }



//        $db = Application::getApp()->get('db');
//        $result = $db->createCommand('select * from tbl_users limit 1')->queryAll();
//        dump($result);

//        \Swoole\Coroutine::create(function () {
//            (new \Swoolefy\Core\EventApp)->registerApp(function (EventController $eventApp)  {
//                var_dump('mmmmmmmmmmmmmmmmmmmmmmmmm');
//            });
//        });



//        if($this->isWorker0()) {
//            $this->notifyMasterCreateDynamicProcess($this->getProcessName(), 1);
//        }

        var_dump($this->limitCurrentRunCoroutineNum);
//        if($this->isWorker0()) {
//            sleep(5);
//            $this->reboot();
//        }
    }

    public function onHandleException(\Throwable $throwable, array $context = [])
    {
        parent::onHandleException($throwable, $context);
        var_dump($throwable->getMessage());
    }
}