<?php
namespace Test\Process\SubscribeProcess;

use Swoolefy\Core\Application;
use Swoolefy\Core\Process\AbstractProcess;

class Subscribe extends AbstractProcess
{
    /**
     * @inheritDoc
     */
    public function run()
    {
        $redis = Application::getApp()->get('redis');
        $pubSub = new \Common\Library\PubSub\RedisPubSub($redis);

        $num = 1;
        \Swoole\Timer::tick(3000, function () use(& $num) {
            // 注册一个协程单例
            (new \Swoolefy\Core\EventApp())->registerApp(function () use(& $num) {
                $redis = Application::getApp()->get('redis');
                $pubSub = new \Common\Library\PubSub\RedisPubSub($redis);
                $pubSub->publish('test1','hello, test subscribe no='.$num);
                $num++;
                var_dump($num);
            });

        });

        while (true)
        {
            try {
                $pubSub->subscribe(['test1'], function($redis, $chan, $msg) use($pubSub) {
                    switch ($chan)
                    {
                        case 'test1':
                            goApp(function () use($msg) {
                                var_dump('redis receive subscribe msg ='.$msg);
                                $handle = new SubscribeHandle();
                                $handle->doRun($msg);
                            });
                            break;

                    }
                });
            }catch (\Throwable $e)
            {

            }
        }
    }


}