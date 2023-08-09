<?php
namespace Test\Process\ListProcess;

use Common\Library\Queues\Queue;
use Swoolefy\Core\Application;
use Swoolefy\Core\Process\AbstractProcess;

class RedisList extends AbstractProcess {

    const queue_order_list = 'queue:order:list';
    /**
     * @inheritDoc
     */
    public function run()
    {
        \Swoole\Timer::tick(2000, function () {
            goApp(function () {
                /**
                 * @var Queue $queue
                 */
                $queue = Application::getApp()->get('queue');
                $queue->push(['name'=> 'bingcool','num' => rand(1,10000)]);
            });
        });

        /**
         * @var Queue $queue
         */
        $queue = Application::getApp()->get('queue');

        while (true) {
            try {
                // 控制协程并发数
                if($this->getCurrentRunCoroutineNum() <= 20) {
                    $data = $queue->pop(3);
                    // 创建协程单例
                    goApp(function () use($data){
                        $list = new \Test\Process\ListProcess\ListController($data);
                        $list->doHandle();
                    });
                    //var_dump('This is Redis List Queue process, pop item='.$data);
                }

            }catch (\Throwable $e)
            {

            }
        }

    }
}