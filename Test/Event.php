<?php

namespace Test;

use Swoolefy\Library\Db\Interceptor\TenantBootstrap;
use Swoolefy\Library\Db\Interceptor\TenantLineDemoHandler;
use Swoolefy\Library\Db\PDOConnection;
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
     * 是否注册 Test\Process\MqVerify 下的消息中间件验证进程。验证完改回 false。
     */
    private const MQ_VERIFY = false;

    /**
     * onInit
     */
    public function onInit()
    {
        // 注册慢sql的宏函数处理
        PDOConnection::registerSlowSqlFn(1, function ($runTime, $realSql, $traceId) {
            var_dump("链路ID： $traceId, slow sql 耗时：$runTime, sql：$realSql");
        });

        // 注册全局租户 SQL 拦截器（同时绑定 Model Scope Handler）
        TenantBootstrap::register(new TenantLineDemoHandler());
    }

    /**
     * 协议服务启动前注册自定义进程。
     */
    public function onStartRegisterProcess()
    {

        // ProcessManager::getInstance()->addProcess('rocketmq-publish-test-demo', \Test\Process\Rocketmq\ProducerRocketmq::class);

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

            // redis list：组件名 queue，Redis 键 queue:order:list。生产、消费分开，同一个组件只开一个消费进程
            // ProcessManager::getInstance()->addProcess('redis-list-publish', \Test\Process\ListProcess\RedisListPublish::class);
            // ProcessManager::getInstance()->addProcess('redis_list_test', \Test\Process\ListProcess\RedisList::class,true, [], null, true);

            // Job 轻量封装 demo：统一信封 + JobRunner 重试/死信（见 docs/Job.md）
            // ProcessManager::getInstance()->addProcess('job-order-notify', \Test\Process\JobProcess\OrderNotifyConsumer::class, true, [], null, true);
            // 多 jobType 共进程（Registry）
            // ProcessManager::getInstance()->addProcess('job-redis-multi', \Test\Process\JobProcess\JobRedisMultiConsumer::class, true, [], null, true);
            // AMQP / Kafka Job 信封 demo
            // ProcessManager::getInstance()->addProcess('job-amqp-consumer', \Test\Process\JobProcess\JobAmqpConsumer::class, true, [], null, true);
            // ProcessManager::getInstance()->addProcess('job-kafka-consumer', \Test\Process\JobProcess\JobKafkaConsumer::class, true, [], null, true);

            // redis 延迟队列：组件名 delayQueue，Redis 键 queue:order:delay。生产时延迟 2 秒，同一个组件只开一个消费进程
            //ProcessManager::getInstance()->addProcess('redis-delay-publish', \Test\Process\QueueProcess\QueuePublish::class);
            //ProcessManager::getInstance()->addProcess('redis_delay_list_test', \Test\Process\QueueProcess\Queue::class,true, [], null, true);


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

            // 死信队列：消息在延迟队列停留 10 秒后进入对应模式的死信队列
            //ProcessManager::getInstance()->addProcess('amqp-publish-dlx-direct', \Test\Process\AmqpProcess\AmqpPublishDlxDirect::class);
            //ProcessManager::getInstance()->addProcess('amqp-consumer-dlx-direct', \Test\Process\AmqpProcess\AmqpConsumerDlxDirect::class);
            //ProcessManager::getInstance()->addProcess('amqp-publish-dlx-fanout', \Test\Process\AmqpProcess\AmqpPublishDlxFanout::class);
            //ProcessManager::getInstance()->addProcess('amqp-consumer-dlx-fanout', \Test\Process\AmqpProcess\AmqpConsumerDlxFanout::class);
            //ProcessManager::getInstance()->addProcess('amqp-publish-dlx-topic', \Test\Process\AmqpProcess\AmqpPublishDlxTopic::class);
            //ProcessManager::getInstance()->addProcess('amqp-consumer-dlx-topic', \Test\Process\AmqpProcess\AmqpConsumerDlxTopic::class);

            // kafka-topic 生产队列
            //ProcessManager::getInstance()->addProcess('kafka-publish-topic', \Test\Process\Kafka\ProducerKafka::class);
            // kafka-topic 消费队列
            //ProcessManager::getInstance()->addProcess('kafka-consumer-topic1', \Test\Process\Kafka\ConsumerKafka::class);
            //ProcessManager::getInstance()->addProcess('kafka-consumer-topic2', \Test\Process\Kafka\ConsumerKafka::class);
            //ProcessManager::getInstance()->addProcess('kafka-consumer-topic3', \Test\Process\Kafka\ConsumerKafka::class);

            // rocketmq test-demo：生产、消费都走 go-sidecar
            ProcessManager::getInstance()->addProcess('rocketmq-publish-test-demo', \Test\Process\Rocketmq\ProducerRocketmq::class);
            // ProcessManager::getInstance()->addProcess('rocketmq-consumer-test-demo', \Test\Process\Rocketmq\ConsumerRocketmq::class);


            // Kafka / AMQP / RocketMQ 真实环境验证：每个场景一个进程，结果看 [MQ-VERIFY] 开头的输出，约 1~3 分钟出结论
            if (self::MQ_VERIFY) {
                $this->registerMqVerifyProcesses();
            }

            // worker进程绑定进程池
            //PoolsManager::getInstance()->addProcessPools('worker-follower-task', \Test\Pools\TestBindWorker::class, 1,true, []);


            // redis的订阅进程
            // ProcessManager::getInstance()->addProcess('redis_subscribe_test', \Test\Process\SubscribeProcess\Subscribe::class);

            // multi call 并发调用进程
            // ProcessManager::getInstance()->addProcess('multi-call', \Test\Process\TestProcess\MultiCall::class);

            // nacos|SDK调用进程

            // Nacos 配置变更 → reload Worker / Task 进程
            // ProcessManager::getInstance()->addProcess(
            //    'nacos-config-reload',
            //    \Test\Process\NacosProcess\NacosConfigReload::class,
            //    true,
            //    [],
            //    null,
            //    true,
            //);

            // Udp服务测试
            // ProcessManager::getInstance()->addProcess('cdp-test', \Test\Process\UdpTestProcess\Udp::class);

            // 这里为什么获取不到pid,那是应为process需要server执行start后才会创建，而在这里只是创建实例，server还没正式启动
            //$pid = ProcessManager::getInstance()->getProcessByName('redis_list_test')->getPid();
        }
    }

    /**
     * 每次启动生成一个 run，所有场景用它隔离 topic / 队列 / 消费组。进程被 kill 重启时 args 不变，run 也不变。
     */
    private function registerMqVerifyProcesses(): void
    {
        $run = date('His') . bin2hex(random_bytes(2));
        foreach (\Test\Process\MqVerify\VerifyScenarios::all() as $scenario) {
            ProcessManager::getInstance()->addProcess('mqv-' . $scenario['name'], $scenario['class'], true, [
                'run' => $run,
                'name' => $scenario['name'],
                'behaviour' => $scenario['behaviour'],
                'mode' => $scenario['mode'],
            ]);
        }
        ProcessManager::getInstance()->addProcess('mqv-reporter', \Test\Process\MqVerify\VerifyReporter::class, true, [
            'run' => $run,
        ]);
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