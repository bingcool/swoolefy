<?php

namespace Test;

use Common\Library\Db\PDOConnection;
use Predis\Command\Redis\CONFIG;
use Swoole\Coroutine\WaitGroup;
use Swoole\Server;
use Swoolefy\Core\Application;
use Swoolefy\Core\Coroutine\GoWaitGroup;
use Swoolefy\Core\ProcessPools\PoolsManager;
use Swoolefy\Core\Swfy;
use Swoolefy\Core\Log\LogManager;
use Swoolefy\Core\EventHandler;
use Swoolefy\Core\Process\ProcessManager;
use Swoolefy\Core\SystemEnv;

class Event extends EventHandler
{
    /**
     * onInit
     */
    public function onInit()
    {
        // 注册慢sql的宏函数处理
        PDOConnection::registerSlowSqlFn(1, function ($runTime, $realSql, $traceId) {
            var_dump("链路ID： $traceId, slow sql 耗时：$runTime, sql：$realSql");
        });

        $waitGroup = new GoWaitGroup();

        if(!SystemEnv::isWorkerService()) {
            // 创建一个测试自定义进程
            // ProcessManager::getInstance()->addProcess('test', \Test\Process\TestProcess\MultiCall::class);

            // 创建一个定时器处理进程
            // ProcessManager::getInstance()->addProcess('tick', \Test\Process\TickProcess\Tick::class);

            // 测试cron自定义进程
            // ProcessManager::getInstance()->addProcess('cron', \Test\Process\CronProcess\Cron::class);

            // 这里为什么获取不到pid,那是应为process需要server执行start后才会创建，而在这里只是创建实例，server还没正式启动
            //$pid = ProcessManager::getInstance()->getProcessByName('cron')->getPid();
            //var_dump('pid='.$pid);

            // redis的队列消费
            // ProcessManager::getInstance()->addProcess('redis_list_test', \Test\Process\ListProcess\RedisList::class,true, [], null, true);

            // redis的延迟队列消费
            // ProcessManager::getInstance()->addProcess('redis_delay_list_test', \Test\Process\QueueProcess\Queue::class,true, [], null, true);


            // amqp-direct 生产队
           // ProcessManager::getInstance()->addProcess('amqp-publish', \Test\Process\AmqpProcess\AmqpPublish::class);
            // amqp-direct 消费队列
           // ProcessManager::getInstance()->addProcess('amqp-consumer', \Test\Process\AmqpProcess\AmqpConsumer::class);
            // amqp-direct 消费队列
           // ProcessManager::getInstance()->addProcess('amqp-consumer1', \Test\Process\AmqpProcess\AmqpConsumer::class);

            // ProcessManager::getInstance()->addProcess('amqp-consumer-1', \Test\Process\AmqpProcess\AmqpConsumer::class);

            // amqp-fanout 生产队列
            //ProcessManager::getInstance()->addProcess('amqp-publish-fanout', \Test\Process\AmqpProcess\AmqpPublishFanout::class);
            // amqp-fanout 消费队列1
            //ProcessManager::getInstance()->addProcess('amqp-consumer-fanout', \Test\Process\AmqpProcess\AmqpConsumerFanout::class);
            // amqp-fanout 消费队列2
            //ProcessManager::getInstance()->addProcess('amqp-consumer-fanout1', \Test\Process\AmqpProcess\AmqpConsumerFanout1::class);


            // amqp-topic 生产队
            //ProcessManager::getInstance()->addProcess('amqp-publish-topic', \Test\Process\AmqpProcess\AmqpPublishTopic::class);
            // amqp-topic 消费队列
            //ProcessManager::getInstance()->addProcess('amqp-consumer-topic', \Test\Process\AmqpProcess\AmqpConsumerTopic::class);

            // kafka-topic 生产队列
            //ProcessManager::getInstance()->addProcess('kafka-publish-topic', \Test\Process\Kafka\ProducerKafka::class);
            // kafka-topic 消费队列
            //ProcessManager::getInstance()->addProcess('kafka-consumer-topic1', \Test\Process\Kafka\ConsumerKafka::class);
            //ProcessManager::getInstance()->addProcess('kafka-consumer-topic2', \Test\Process\Kafka\ConsumerKafka::class);
            //ProcessManager::getInstance()->addProcess('kafka-consumer-topic3', \Test\Process\Kafka\ConsumerKafka::class);


            // worker进程绑定进程池
            //PoolsManager::getInstance()->addProcessPools('worker-follower-task', \Test\Pools\TestBindWorker::class, 1,true, []);


            // redis的订阅进程
            // ProcessManager::getInstance()->addProcess('redis_subscribe_test', \Test\Process\SubscribeProcess\Subscribe::class);

            // multi call 并发调用进程
            // ProcessManager::getInstance()->addProcess('multi-call', \Test\Process\TestProcess\MultiCall::class);

            // Udp服务测试
            // ProcessManager::getInstance()->addProcess('cdp-test', \Test\Process\UdpTestProcess\Udp::class);

            // 这里为什么获取不到pid,那是应为process需要server执行start后才会创建，而在这里只是创建实例，server还没正式启动
            //$pid = ProcessManager::getInstance()->getProcessByName('redis_list_test')->getPid();
        }
    }

    /**
     * onWorkerServiceInit
     */
    public function onWorkerServiceInit()
    {

    }

    /**
     * onWorkerStart
     * @param $server
     * @return void
     */
    public function onWorkerStart($server, $worker_id)
    {
        if($this->isWorkerService() || Swfy::isTaskProcess()) {
            return;
        }
        // 创建产生uuid的定时器
        // App::getUUid()->registerTickPreBatchGenerateIds(2001, 100);
    }

    public function onWorkerStop($server, $worker_id)
    {
        if (!SystemEnv::isScriptService()) {
            // var_dump(Application::getApp()->get('db'));
        }
    }
}