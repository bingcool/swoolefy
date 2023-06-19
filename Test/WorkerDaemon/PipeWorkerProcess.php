<?php

namespace Test\WorkerDaemon;

use Swoolefy\Core\Application;
use Swoolefy\Core\Coroutine\Timer;
use Swoolefy\Core\Log\LogManager;

class PipeWorkerProcess extends \Swoolefy\Worker\AbstractWorkerProcess
{
    public function run()
    {
        Timer::tick(2000, function () {
            var_dump('hello word');
        });
        Application::getApp()->get('log')->addInfo('pllllllllllll');
        while (1) {
            var_dump('CID='.\Swoole\Coroutine::getCid());
            var_dump('PipeWorker');
            $a = 1;
            $b = 2;
            goApp(function ($a, $b) {
                var_dump($a, $b);
            },...[$a, $b]);

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

        $log = LogManager::getInstance()->getLogger('log');
        $log->addInfo('test222222-log-id='.rand(1,1000),true);

//        if($this->isWorker0()) {
//            $this->notifyMasterCreateDynamicProcess($this->getProcessName(), 1);
//        }

//        if($this->isWorker0()) {
//            sleep(5);
//            $this->reboot();
//        }

        /**
         * @var \Common\Library\Db\Mysql $db
         */
        $db = Application::getApp()->get('db');
        $count = $db->createCommand("select count(1) as total from tbl_users")->count();
        var_dump('user count='.$count);
    }

    public function onHandleException(\Throwable $throwable, array $context = [])
    {
        parent::onHandleException($throwable, $context);
        var_dump($throwable->getMessage());
    }
}