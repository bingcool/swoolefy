<?php

namespace Test;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\Application;
use Swoolefy\Core\Log\LogManager;
use Swoolefy\Core\EventHandler;
use Swoolefy\Core\Process\ProcessManager;

class Event extends EventHandler
{
    /**
     * onInit
     */
    public function onInit() {
        // log register
        $appConf = Swfy::getAppConf();
        if(isset($appConf['components']['log'])) {
            $log = $appConf['components']['log'];
            if($log instanceof \Closure) {
                LogManager::getInstance()->registerLoggerByClosure($log, 'log');
            }
        }

        if(isset($appConf['components']['error_log'])) {
            $log = $appConf['components']['error_log'];
            if($log instanceof \Closure) {
                LogManager::getInstance()->registerLoggerByClosure($log, 'error_log');
            }
        }

        if(!$this->isWorkerService()) {
            // todo--refer to Test Demo
        }
    }

    /**
     * onWorkerServiceInit
     */
    public function onWorkerServiceInit()
    {
        // todo--refer to Test Demo
    }

    /**
     * onWorkerStart
     * @param $server
     * @param $worker_id
     * @return void
     */
    public function onWorkerStart($server, $worker_id)
    {
        if ($this->isWorkerService()) {
            // todo
        }
    }

    /**
     * @param $server
     * @param $worker_id
     * @return void
     */
    public function onWorkerStop($server, $worker_id)
    {
        //todo
    }
}