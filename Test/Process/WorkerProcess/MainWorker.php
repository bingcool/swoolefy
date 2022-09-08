<?php
namespace Test\Process\WorkerProcess;

use Swoole\Process;
use Swoolefy\Core\Swfy;
use Swoolefy\Core\Application;
use Swoolefy\Core\Process\AbstractProcess;
use Swoolefy\Core\Process\ProcessManager;
use Swoolefy\Core\Timer\TickManager;


class MainWorker extends AbstractProcess {

    /**
     *
     */
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
        $this->parseCliEnvParams();
    }

    /**
     * @return array
     */
    protected function parseCliEnvParams()
    {
        $cliParams = [];
        $args = array_splice($_SERVER['argv'], 3);
        array_reduce($args, function ($result, $item) use (&$cliParams) {
            // start daemon
            if (in_array($item, ['-d', '-D'])) {
                putenv('daemon=1');
                defined('IS_DAEMON') OR define('IS_DAEMON', 1);
            } else if (in_array($item, ['-f', '-F'])) {
                // stop force
                putenv('force=1');
                $cliParams['force'] = 1;
            } else {
                $item = ltrim($item, '--');
                putenv($item);
                list($env, $value) = explode('=', $item);
                if ($env && $value) {
                    $cliParams[$env] = $value;
                }
            }
        });
        defined('WORKER_CLI_PARAMS') or define('WORKER_CLI_PARAMS', json_encode($cliParams,JSON_UNESCAPED_UNICODE));
        return $cliParams;
    }

    /**
     *
     */
    public function run()
    {
        var_dump('cid='.\Swoole\Coroutine::getCid());

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