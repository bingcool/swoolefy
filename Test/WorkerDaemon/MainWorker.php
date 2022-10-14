<?php
namespace Test\WorkerDaemon;

use Swoolefy\Core\EventController;
use Swoolefy\Worker\AbstractMainWorker;

class MainWorker extends AbstractMainWorker {
    /**
     * @return void
     */
    public function run()
    {
        try {
            $mainManager = \Swoolefy\Worker\MainManager::getInstance();
            $mainManager->start();
        }catch (\Throwable $exception) {
            var_dump($exception->getMessage(), $exception->getTraceAsString());
        }
    }
}