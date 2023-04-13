<?php

namespace Test;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\Application;
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
        $appConf = Swfy::getAppConf();
        if(isset($appConf['components']['log'])) {
            $log = $appConf['components']['log'];
            if($log instanceof \Closure) {
                LogManager::getInstance()->registerLoggerByClosure($log, 'log');
            }
        }

        if(isset($appConf['components']['error_log'])) {
            $log = $appConf['components']['error_log'];
            if($log instanceof \Closure) {
                LogManager::getInstance()->registerLoggerByClosure($log, 'error_log');
            }
        }

        if(!$this->isWorkerService()) {
            // 创建一个测试自定义进程
            // ProcessManager::getInstance()->addProcess('test', \Test\Process\TestProcess\Test::class);

            // 创建一个定时器处理进程
            //ProcessManager::getInstance()->addProcess('tick', \Test\Process\TickProcess\Tick::class);

            // 测试cron自定义进程
            ProcessManager::getInstance()->addProcess('cron', \Test\Process\CronProcess\Cron::class);

            // 这里为什么获取不到pid,那是应为process需要server执行start后才会创建，而在这里只是创建实例，server还没正式启动
            //$pid = ProcessManager::getInstance()->getProcessByName('cron')->getPid();
            //var_dump('pid='.$pid);

            // redis的队列消费
            //ProcessManager::getInstance()->addProcess('redis_list_test', \Test\Process\ListProcess\RedisList::class,true, [], null, true);

            // amqp-direct 生产队
            //ProcessManager::getInstance()->addProcess('amqp-publish', \Test\Process\AmqpProcess\AmqpPublish::class);
            // amqp-direct 消费队列
            //ProcessManager::getInstance()->addProcess('amqp-consumer', \Test\Process\AmqpProcess\AmqpConsumer::class);
            //ProcessManager::getInstance()->addProcess('amqp-consumer-1', \Test\Process\AmqpProcess\AmqpConsumer::class);

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
            //ProcessManager::getInstance()->addProcess('redis_subscribe_test', \Test\Process\SubscribeProcess\Subscribe::class);

            // 这里为什么获取不到pid,那是应为process需要server执行start后才会创建，而在这里只是创建实例，server还没正式启动
            //$pid = ProcessManager::getInstance()->getProcessByName('redis_list_test')->getPid();
        }
    }

    /**
     * onWorkerServiceInit
     */
    public function onWorkerServiceInit()
    {
        switch (WORKER_SERVICE_NAME) {
            case 'test-worker':
                ProcessManager::getInstance()->addProcess(WORKER_SERVICE_NAME, \Test\WorkerDaemon\MainWorker::class, true,  [],null, false);
                break;
            case 'test-worker-cron':
                ProcessManager::getInstance()->addProcess(WORKER_SERVICE_NAME, \Test\WorkerCron\MainCronWorker::class, true,  [],null, false);
                break;
            case 'test-script':
                $class = \Swoolefy\Script\MainCliScript::parseClass();
                if(empty($class)) {
                    exit(0);
                }
                ProcessManager::getInstance()->addProcess(WORKER_SERVICE_NAME, $class);
                break;
            default:
                write('Missing onWorkerServiceInit handle');
                exit(0);
        }
    }

    /**
     * onWorkerStart
     * @param $server
     * @return void
     */
    public function onWorkerStart($server, $worker_id)
    {
        if($this->isWorkerService()) {
            return;
        }

        // 创建产生uuid的定时器
        //$redis = Application::getApp()->get('redis')->getObject();
        //\Common\Library\Uuid\UuidManager::getInstance($redis, 'uuid-key')->tickPreBatchGenerateIds(2,1000);
    }

    public function onWorkerStop($server, $worker_id)
    {
        //var_dump(Application::getApp()->get('db'));
    }
}