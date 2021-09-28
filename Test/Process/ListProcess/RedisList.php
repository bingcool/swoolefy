<?php
namespace Test\Process\ListProcess;

use Swoole\Process;
use Swoolefy\Core\Swfy;
use Swoolefy\Core\Application;
use Swoolefy\Core\Process\AbstractProcess;
use Swoolefy\Core\Process\ProcessManager;
use Swoolefy\Core\Timer\TickManager;
use Swoolefy\Core\Crontab\CrontabManager;

class RedisList extends AbstractProcess {

    public $queseKey = 'order:list';
    /**
     * @inheritDoc
     */
    public function run()
    {
        $redis = Application::getApp()->get('predis');

        $queue = new \Common\Library\Queues\Queue($redis, $this->queseKey);

        $queue->push(['name'=> 'bingcool']);

        while (true)
        {
            try {
                // 控制协程并发数
                if($this->getCurrentRunCoroutineNum() <= 20)
                {
                    $data = $queue->pop(3);
                    // 创建协程单例
                    go(function () use($data){
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