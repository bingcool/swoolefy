<?php
namespace Test\WorkerDaemon;

use Swoolefy\Worker\AbstractMainProcess;

class MainProcess extends AbstractMainProcess {
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

            // 启动上报函数
//            \Swoole\Timer::after(10 * 1000, function () {
//                var_dump("start success！");
//            });

            $mainManager->start();
        }catch (\Throwable $exception) {
            var_dump($exception->getMessage(), $exception->getTraceAsString());
        }
    }
}