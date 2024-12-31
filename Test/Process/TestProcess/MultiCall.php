<?php

namespace Test\Process\TestProcess;

use Swoolefy\Core\BaseServer;
use Swoolefy\Core\Coroutine\GoWaitGroup;
use Swoolefy\Core\Process\AbstractProcess;
use Swoolefy\Core\SyncPipe;
use Test\App;

class MultiCall extends AbstractProcess
{

    /**
     * @inheritDoc
     */
    public function run()
    {
        $this->syncPipeCall();
    }

    protected function syncPipeCall()
    {
        $syncPipe = new SyncPipe();

        $result = $syncPipe->start(function () {
                return 'aaaaa';
            })->then(function ($param) {
                var_dump($param);
                sleep(5);
                //return "bbbbb";
            })->then(function ($param) {
                var_dump($param);
                return "ccccc";
            })->run();

        var_dump($result);

        var_dump('main syncPipeCall coroutine ');

    }

    protected function parallelCall()
    {
        while (true) {
            try {
                sleep(2);
                $result = GoWaitGroup::batchParallelRunWait([
                        'key1' => function ($param) {
                            sleep(3);

                            var_dump($param);

                            $db = App::getDb();
                            var_dump(spl_object_id($db), \Swoole\Coroutine::getCid());

                            goApp(function($event) {
                                $db = App::getDb();
                                var_dump(spl_object_id($db),\Swoole\Coroutine::getCid());
                            });

                            goApp(function($event) {
                                $db = App::getDb();
                                var_dump(spl_object_id($db), \Swoole\Coroutine::getCid());
                            });

                            var_dump(spl_object_id(App::getDb()));

                            return "aaaaa";
                        },
                        'key2' => function () {
                            sleep(3);
                            return "bbbbb";
                        },
                        'key3' => function () {
                            sleep(10);
                            return "cccccccc";
                        }
                    ]
                    , 5,[
                        'key1' => 'param1',
                        'key2' => 'param2',
                    ]);

                var_dump($result);


                // 不同逻辑时可以分开单个处理
                $goWaitGroup = new GoWaitGroup();
                $goWaitGroup->add(1);
                goApp(function () use ($goWaitGroup) {
                    $db = App::getDb();
                    var_dump(spl_object_id($db), \Swoole\Coroutine::getCid());
                    $goWaitGroup->done('key1', 'aaaaa11111');
                });

                $goWaitGroup->add(1);
                goApp(function () use ($goWaitGroup) {
                    $db = App::getDb();
                    sleep(5);
                    var_dump(spl_object_id($db), \Swoole\Coroutine::getCid());
                    $goWaitGroup->done('key2', 'bbbbb1111');
                });

                $goWaitGroup->add(1);
                goApp(function () use ($goWaitGroup) {
                    $db = App::getDb();
                    var_dump(spl_object_id($db), \Swoole\Coroutine::getCid());
                    sleep(3);
                    $goWaitGroup->done('key3', 'cccccccc1111');
                });

                $result = $goWaitGroup->wait(6);
                var_dump($result);

            } catch (\Throwable $e) {
                BaseServer::catchException($e);
            }
        }
    }

    public function onReceive($msg, ...$args)
    {
        // receive from worker process
        var_dump('This is Test Process Receive Msg From Worker, Msg=' . $msg);

        // write to worker process
        $this->getProcess()->write('hello, I am Test Process');
    }
}