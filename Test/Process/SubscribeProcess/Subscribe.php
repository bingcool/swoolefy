<?php
namespace Test\Process\SubscribeProcess;

use Swoolefy\Core\Application;
use Swoolefy\Core\Coroutine\Timer;
use Swoolefy\Core\Process\AbstractProcess;
use Test\Factory;
use Test\Module\Order\OrderList;

class Subscribe extends AbstractProcess
{
    /**
     * @inheritDoc
     */
    public function run()
    {
        $pubSub = Factory::getRedisSubscribe();
        $num = 1;
        $timeNow = time();

        Timer::tick(500, function ($timeChannel) use(& $num, $timeNow) {
            if (time() - $timeNow > 3) {
                var_dump('close close');
                Timer::cancel($timeChannel);
            }
            // 注册一个协程单例
            $pubSub = Factory::getRedisSubscribe();
            $pubSub->publish('test1','hello, test subscribe no='.$num);
            $num++;
        });

        while (true) {
            try {
                $pubSub->subscribe(['test1'], function($redis, $chan, $msg) use($pubSub) {
                    switch ($chan) {
                        case 'test1':
                            sleep(1);
                                var_dump('redis receive subscribe msg ='.$msg);
                                $handle = new SubscribeHandle();
                                $handle->doRun($msg);
                            break;

                    }
                });
            }catch (\Throwable $e) {

            }
        }
    }


}