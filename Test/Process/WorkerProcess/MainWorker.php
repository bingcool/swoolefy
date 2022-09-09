<?php
namespace Test\Process\WorkerProcess;

use Swoolefy\Worker\AbstractMainWorker;

class MainWorker extends AbstractMainWorker {

    protected function beforeStart()
    {
        define('WORKER_MASTER_ID', $this->getPid());
//        define('WORKER_START_SCRIPT_FILE', $_SERVER['PWD'].'/'.$_SERVER['SCRIPT_FILENAME']);
//        define('WORKER_PID_FILE_ROOT', '/tmp/workerfy/log/test-worker');
//        define('WORKER_PID_FILE', WORKER_PID_FILE_ROOT.'/worker.pid');
//        define('WORKER_STATUS_FILE',WORKER_PID_FILE_ROOT.'/status.txt');
//        define('WORKER_CTL_LOG_FILE',WORKER_PID_FILE_ROOT.'/ctl.txt');
//        define('WORKER_APP_ROOT', __DIR__.'/Test/workerDaemon');
        $this->parseCliEnvParams();
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
                var_dump('onStart Cid='.\Co::getCid());
            };

            $mainManager->start();

        }catch (\Throwable $exception) {
            var_dump($exception->getMessage(), $exception->getTraceAsString());
        }
    }
}