<?php
namespace Test\WorkerCron;

use Swoolefy\Worker\AbstractMainProcess;

class MainCronProcess extends AbstractMainProcess {
    /**
     * @return void
     */
    public function run()
    {
        try {
            $mainManager = \Swoolefy\Worker\MainManager::getInstance();
            // 状态上报存表
            $mainManager->onReportStatus = function (array $status) {

            };
            $mainManager->start();
        }catch (\Throwable $exception) {
            $this->onHandleException($exception);
        }
    }
}