<?php
namespace Test\WorkerCron;

use Swoolefy\Worker\AbstractMainWorker;

class MainCronWorker extends AbstractMainWorker {
    /**
     * @return void
     */
    public function run()
    {
        try {
            $mainManager = \Swoolefy\Worker\MainManager::getInstance();
            $mainManager->start();
        }catch (\Throwable $exception) {
            $this->onHandleException($exception);
        }
    }
}