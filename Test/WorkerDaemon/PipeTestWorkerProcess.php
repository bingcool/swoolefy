<?php

namespace Test\WorkerDaemon;

use Swoolefy\Core\Application;
use Swoolefy\Core\Log\LogManager;

class PipeTestWorkerProcess extends \Swoolefy\Worker\AbstractWorkerProcess
{

    public function run()
    {
        while (1) {
            var_dump("PipeTestWorkerProcess PipeTestWorkerProcess PipeTestWorkerProcess");
            sleep(2);
        }


        //LogManager::getInstance()->getLogger('log')->addInfo('worker hello!');
        //$this->notifyMasterCreateDynamicProcess('test-pipe-worker',1);
    }
}