<?php
namespace Test\Process\WorkerProcess;

use Swoole\Process;
use Swoolefy\Core\Swfy;
use Swoolefy\Core\Application;
use Swoolefy\Core\Process\AbstractProcess;
use Swoolefy\Core\Process\ProcessManager;
use Swoolefy\Core\Timer\TickManager;


class MainWorker extends AbstractProcess {

    protected function beforeStart()
    {
        date_default_timezone_set('Asia/Shanghai');
        define('WORKER_START_SCRIPT_FILE', $_SERVER['PWD'].'/'.$_SERVER['SCRIPT_FILENAME']);
        define('WORKER_PID_FILE_ROOT', '/tmp/workerfy/log/test-worker');
        define('WORKER_PID_FILE', WORKER_PID_FILE_ROOT.'/worker.pid');
        define('WORKER_STATUS_FILE',WORKER_PID_FILE_ROOT.'/status.txt');
        define('WORKER_CTL_LOG_FILE',WORKER_PID_FILE_ROOT.'/ctl.txt');
        define('WORKER_APP_ROOT', APP_NAME.'/workerDaemon');
        define('WORKER_MASTER_ID', $this->getPid());

        $globalEnv = 'dev';
        $envFile = START_DIR_ROOT.'/env.ini';
        if(file_exists($envFile)) {
            $options = parse_ini_file($envFile, true);
            $env = $options['global']['env'] ?? '';
            if($env) {
                $globalEnv = $env;
            }
        }

        defined('WORKER_ENV') or define('WORKER_ENV', $globalEnv);
    }

    /**
     *
     */
    public function run()
    {
        var_dump('cid='.\Swoole\Coroutine::getCid());

        try {
            $mainManager = \Swoolefy\Worker\MainManager::getInstance([
                'report_status_tick_time' => 5
            ]);
            $process_name = 'test-worker';
            $process_class = \Test\WorkerDaemon\PipeWorker::class;
            $process_worker_num = 1;
            $async = true;
            $args = [
                'wait_time' => 1
            ];
            $extend_data = null;

            $mainManager->addProcess($process_name, $process_class, $process_worker_num, $async, $args, $extend_data);

            $mainManager->start();

        }catch (\Throwable $exception) {
            var_dump($exception->getMessage(), $exception->getTraceAsString());
        }

        \Swoole\Event::wait();
    }
}