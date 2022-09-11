<?php
namespace Test\WorkerDaemon;

use Swoolefy\Worker\AbstractMainWorker;

class MainCronWorker extends AbstractMainWorker {

    protected function beforeStart()
    {
    }

    public function run()
    {
        try {
            $mainManager = \Swoolefy\Worker\MainManager::getInstance();
            $process_name = 'test-worker';
            $process_class = \Test\WorkerDaemon\PipeWorker::class;
            $process_worker_num = 1;
            $async = true;
            $args = [
                'wait_time' => 1
            ];
            $extend_data = null;

            $mainManager->addProcess($process_name, $process_class, $process_worker_num, $async, $args, $extend_data);

            $mainManager->onStart = function () {
                //var_dump('onStart Cid='.\Co::getCid());
            };

            $mainManager->start();

        }catch (\Throwable $exception) {
            var_dump($exception->getMessage(), $exception->getTraceAsString());
        }
    }
}