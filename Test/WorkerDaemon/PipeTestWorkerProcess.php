<?php

namespace Test\WorkerDaemon;

use Swoolefy\Core\Application;
use Swoolefy\Core\Log\LogManager;
use Test\Logger\RunLog;

class PipeTestWorkerProcess extends \Swoolefy\Worker\AbstractWorkerProcess
{

    public function run()
    {
        while (1) {
//            RunLog::info("PipeTestWorkerProcess test log");
//            RunLog::error("PipeTestWorkerProcess error log  test");
            var_dump("PipeTestWorkerProcess PipeTestWorkerProcess PipeTestWorkerProcess");
            sleep(20);
        }


        //LogManager::getInstance()->getLogger('log')->addInfo('worker hello!');
        //$this->notifyMasterCreateDynamicProcess('test-pipe-worker',1);
    }
}