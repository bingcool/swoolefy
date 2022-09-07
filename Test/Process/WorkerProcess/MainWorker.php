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
    public function run()
    {
        var_dump('cid='.\Swoole\Coroutine::getCid());

        try {

            $process = new Process(function () {
                var_dump('hello');
            }, false, 2, true);
            $process->start();

        }catch (\Throwable $exception) {
            var_dump($exception->getMessage());
        }

        \Swoole\Event::wait();
    }
}