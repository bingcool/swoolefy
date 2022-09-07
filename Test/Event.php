<?php

namespace Test;

use Swoole\Process;
use Swoolefy\Core\Application;
use Swoolefy\Core\EventApp;
use Swoolefy\Core\ProcessPools\PoolsManager;
use Swoolefy\Core\Swfy;
use Swoolefy\Core\Log\LogManager;
use Swoolefy\Core\EventHandler;
use Swoolefy\Core\Process\ProcessManager;

class Event extends EventHandler
{
    /**
     * onInit
     */
    public function onInit() {

        // log register
        $app_conf = Swfy::getAppConf();
        if(isset($app_conf['components']['log'])) {
            $log = $app_conf['components']['log'];
            if($log instanceof \Closure) {
                LogManager::getInstance()->registerLoggerByClosure($log, 'log');
            }
        }

        if(isset($app_conf['components']['error_log'])) {
            $log = $app_conf['components']['error_log'];
            if($log instanceof \Closure) {
                LogManager::getInstance()->registerLoggerByClosure($log, 'error_log');
            }
        }

        // 创建一个测试自定义进程
        //ProcessManager::getInstance()->addProcess('test', \Test\Process\TestProcess\Test::class);

        // 创建一个定时器处理进程
        //ProcessManager::getInstance()->addProcess('tick', \Test\Process\TickProcess\Tick::class);

        // 测试cron自定义进程
        //ProcessManager::getInstance()->addProcess('cron', \Test\Process\CronProcess\Cron::class);
        // 这里为什么获取不到pid,那是应为process需要server执行start后才会创建，而在这里只是创建实例，server还没正式启动
        //$pid = ProcessManager::getInstance()->getProcessByName('cron')->getPid();
        //var_dump('pid='.$pid);

        // redis的队列消费
        //ProcessManager::getInstance()->addProcess('redis_list_test', \Test\Process\ListProcess\RedisList::class,true, [], null, true);


        // worker进程绑定进程池
        //PoolsManager::getInstance()->addProcessPools('worker-follower-task', \Test\Pools\TestBindWorker::class, 1,true, []);


        // redis的订阅进程
        //ProcessManager::getInstance()->addProcess('redis_subscribe_test', \Test\Process\SubscribeProcess\Subscribe::class);


        // 这里为什么获取不到pid,那是应为process需要server执行start后才会创建，而在这里只是创建实例，server还没正式启动
        //$pid = ProcessManager::getInstance()->getProcessByName('redis_list_test')->getPid();

        ProcessManager::getInstance()->addProcess('worker', \Test\Process\WorkerProcess\MainWorker::class, true,[],null, false);

    }

    /**
     * onWorkerStart
     * @param $server
     * @return void
     */
    public function onWorkerStart($server, $worker_id)
    {
        // 创建产生uuid的定时器
        $redis = Application::getApp()->get('redis')->getObject();
        \Common\Library\Uuid\UuidManager::getInstance($redis, 'uuid-key')->tickPreBatchGenerateIds(2,1000);
    }

    public function onWorkerStop($server, $worker_id)
    {
        //var_dump(Application::getApp()->get('db'));
    }
}