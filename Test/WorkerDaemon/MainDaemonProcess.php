<?php
namespace Test\WorkerDaemon;

use Swoolefy\Worker\AbstractMainProcess;

class MainDaemonProcess extends AbstractMainProcess {
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
            var_dump($exception->getMessage(), $exception->getTraceAsString());
        }
    }
}