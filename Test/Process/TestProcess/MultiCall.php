<?php

namespace Test\Process\TestProcess;

use Swoolefy\Core\BaseServer;
use Swoolefy\Core\Coroutine\GoWaitGroup;
use Swoolefy\Core\Process\AbstractProcess;
use Test\Factory;

class MultiCall extends AbstractProcess
{

    /**
     * @inheritDoc
     */
    public function run()
    {
        while (true) {
            try {
                sleep(2);
                $result = GoWaitGroup::multiCall([
                        'key1' => function () {
                            sleep(3);

                            $db = Factory::getDb();
                            var_dump(spl_object_id($db), \Swoole\Coroutine::getCid() );

                            (new \Swoolefy\Core\EventApp)->registerApp(function($event) {
                                try {
                                    $db = Factory::getDb();
                                    var_dump(spl_object_id($db),\Swoole\Coroutine::getCid());
                                }catch (\Throwable $throwable) {
                                    \Swoolefy\Core\BaseServer::catchException($throwable);
                                }
                            });

                            (new \Swoolefy\Core\EventApp)->registerApp(function($event) {
                                try {
                                    $db = Factory::getDb();
                                    var_dump(spl_object_id($db), \Swoole\Coroutine::getCid() );
                                }catch (\Throwable $throwable) {
                                    \Swoolefy\Core\BaseServer::catchException($throwable);
                                }
                            });

                            var_dump(spl_object_id(Factory::getDb()));

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
                , 5);

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