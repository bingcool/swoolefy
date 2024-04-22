<?php
namespace Test\Process\ListProcess;

use Common\Library\Queues\Queue;
use Swoolefy\Core\Application;
use Swoolefy\Core\Process\AbstractProcess;
use Test\Factory;

class RedisList extends AbstractProcess {

    const queue_order_list = 'queue:order:list';
    /**
     * @inheritDoc
     */
    public function run()
    {
        goTick(2000, function () {
            $queue = Factory::getQueue();
            $queue->push(['name'=> 'bingcool','num' => rand(1,10000)]);
        });

        $queue = Factory::getQueue();

        while (true) {
            try {
                // 控制协程并发数
                if($this->getCurrentRunCoroutineNum() <= 20) {
                    $result = $queue->pop(3);
                    if (empty($result)) {
                        continue;
                    }
                    $data = $result[1];
                    // 创建协程单例
                    goApp(function () use($data) {
                        $list = new \Test\Process\ListProcess\ListController($data);
                        $list->doHandle();
                    });

                    //$queue->retry($data);
                    //var_dump('This is Redis List Queue process, pop item='.$data);
                }

            }catch (\Throwable $e)
            {
                var_dump($e->getMessage());
            }
        }

    }
}