<?php
// 应用配置
use Common\Library\Amqp\AmqpStreamConnectionFactory;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Swoolefy\Core\Application;
use Test\Config\AmqpConfig;
use Test\Config\KafkaConfig;

$dc = \Swoolefy\Core\SystemEnv::loadDcEnv();

return [

    // db|redis连接池
    'enable_component_pools' => [
        'db' => [
            'pools_num' => 5,
            'push_timeout' => 2,
            'pop_timeout' => 1,
            'live_time' => 10
        ],

        'redis' => [
            'pools_num' => 5,
            'push_timeout' => 2,
            'pop_timeout' => 1,
            'live_time' => 10
        ]
    ],

    // default_db
    'default_db' => 'db',

    // 组件
    'components' => [
        // 用户行为记录的日志
        'log' => function($name) {
            $logger = new \Swoolefy\Util\Log($name);
            $logger->setChannel('application');
            if(isDaemonService()) {
                $logFilePath = LOG_PATH.'/daemon.log';
            }else if (isScriptService()) {
                $logFilePath = LOG_PATH.'/script.log';
            }else if (isCronService()) {
                $logFilePath = LOG_PATH.'/cron.log';
            } else {
                $logFilePath = LOG_PATH.'/runtime.log';
            }
            $logger->setLogFilePath($logFilePath);
            return $logger;
        },

        // 系统捕捉异常错误日志
        'error_log' => function($name) {
            $logger = new \Swoolefy\Util\Log($name);
            $logger->setChannel('application');
            if(isDaemonService()) {
                $logFilePath = LOG_PATH.'/daemon_error.log';
            }else if (isScriptService()) {
                $logFilePath = LOG_PATH.'/script_error.log';
            }else if (isCronService()) {
                $logFilePath = LOG_PATH.'/cron_error.log';
            } else {
                $logFilePath = LOG_PATH.'/error.log';
            }
            $logger->setLogFilePath($logFilePath);
            return $logger;
        },

        'db' => function() use($dc) {
            $db = new \Common\Library\Db\Mysql($dc['mysql_db']);
            return $db;
        },

        'redis' => function() use($dc) {
            $redis = new \Common\Library\Cache\Redis();
            $redis->connect($dc['redis']['host'], $dc['redis']['port']);
            return $redis;
        },

        'predis' => function() use($dc) {
            $predis = new \Common\Library\Cache\predis([
                'scheme' => $dc['predis']['scheme'],
                'host'   => $dc['predis']['host'],
                'port'   => $dc['predis']['port'],
            ]);
            return $predis;
        },

        'uuid' => function() use($dc) {
            $redis = Application::getApp()->get('redis')->getObject();
            return \Common\Library\Uuid\UuidManager::getInstance($redis, 'uuid-key');
        },

        'queue' => function() {
            $redis = Application::getApp()->get('redis')->getObject();
            return new \Common\Library\Queues\Queue($redis,\Test\Process\ListProcess\RedisList::queue_order_list);
        },

        'redis-subscribe' => function() {
            $redis = Application::getApp()->get('redis')->getObject();
            return new \Common\Library\PubSub\RedisPubSub($redis);
        },

        'rateLimit' => function() {
            $redis = Application::getApp()->get('redis')->getObject();
            $rateLimit =  new \Common\Library\RateLimit\RedisLimit($redis);
            $rateLimit->setLimitParams(60,60, 3600);
            return $rateLimit;
        },

        'amqpConnection' => function() use($dc) {
            $connection = AmqpStreamConnectionFactory::create(
                $dc['amqp_connection']['host_list'],
                $dc['amqp_connection']['options']
            );
            return $connection;
        },

        // direct queue 发布与消费
        'orderAddDirectQueue' => function() use($dc) {
            /**
             * @var AMQPStreamConnection $connection
             */
            $connection = \Swoolefy\Core\Application::getApp()->get('amqpConnection')->getObject();
            $amqpConfig = new \Common\Library\Amqp\AmqpConfig();
            $amqpConfig->exchangeName = AmqpConfig::AMQP_EXCHANGE_DIRECT_ORDER;
            $amqpConfig->queueName    = AmqpConfig::AMQP_QUEUE_DIRECT_ORDER_ADD;
            $property = AmqpConfig::AMQP_DIRECT[$amqpConfig->exchangeName][$amqpConfig->queueName];
            $amqpConfig->type = $property['type'];
            $amqpConfig->bindingKey = $property['binding_key'];
            $amqpConfig->routingKey = $property['routing_key'];
            $amqpConfig->passive = $property['passive'];
            $amqpConfig->durable = $property['durable'];
            $amqpConfig->exclusive = $property['exclusive'];
            $amqpConfig->autoDelete = $property['auto_delete'];

            $amqpDirect = new \Common\Library\Amqp\AmqpDirectQueue($connection, $amqpConfig);
            $amqpDirect->setAckHandler(function (\PhpAmqpLib\Message\AMQPMessage $message) {
                echo "Message acked with content " . $message->body . PHP_EOL;
            });
            return $amqpDirect;
        },

        // direct queue 发布与消费
        'orderExportDirectQueue' => function() use($dc) {
            /**
             * @var AMQPStreamConnection $connection
             */
            $connection = \Swoolefy\Core\Application::getApp()->get('amqpConnection')->getObject();
            $amqpConfig = new \Common\Library\Amqp\AmqpConfig();
            $amqpConfig->exchangeName = AmqpConfig::AMQP_EXCHANGE_DIRECT_ORDER;
            $amqpConfig->queueName    = AmqpConfig::AMQP_QUEUE_DIRECT_ORDER_EXPORT;
            $property = AmqpConfig::AMQP_DIRECT[$amqpConfig->exchangeName][$amqpConfig->queueName];
            $amqpConfig->type = $property['type'];
            $amqpConfig->bindingKey = $property['binding_key'];
            $amqpConfig->routingKey = $property['routing_key'];
            $amqpConfig->passive = $property['passive'];
            $amqpConfig->durable = $property['durable'];
            $amqpConfig->exclusive = $property['exclusive'];
            $amqpConfig->autoDelete = $property['auto_delete'];

            $amqpDirect = new \Common\Library\Amqp\AmqpDirectQueue($connection, $amqpConfig);

            return $amqpDirect;
        },

        // direct queue 延迟队列
        'orderDelayDirectQueue' => function() use($dc) {
            /**
             * @var AMQPStreamConnection $connection
             */
            $connection = \Swoolefy\Core\Application::getApp()->get('amqpConnection')->getObject();
            $amqpConfig = new \Common\Library\Amqp\AmqpConfig();
            $amqpConfig->exchangeName = AmqpConfig::AMQP_EXCHANGE_DIRECT_ORDER;

            // 延迟名称
            $amqpConfig->queueName    = AmqpConfig::AMQP_QUEUE_DIRECT_ORDER_ADD_DELAY;

            $property = AmqpConfig::AMQP_DIRECT[$amqpConfig->exchangeName][$amqpConfig->queueName];
            $amqpConfig->type = $property['type'];
            $amqpConfig->bindingKey = $property['binding_key'];
            $amqpConfig->routingKey = $property['routing_key'];
            $amqpConfig->passive = $property['passive'];
            $amqpConfig->durable = $property['durable'];
            $amqpConfig->exclusive = $property['exclusive'];
            $amqpConfig->autoDelete = $property['auto_delete'];
            $amqpConfig->arguments = $property['arguments'];

            $amqpDelayDirect = new \Common\Library\Amqp\AmqpDelayDirectQueue($connection, $amqpConfig);
//            $amqpDelayDirect->setAckHandler(function (\PhpAmqpLib\Message\AMQPMessage $message) {
//                echo "Message acked with content " . $message->body . PHP_EOL;
//            });
            return $amqpDelayDirect;
        },


        // 交换机下的fanout模式数据广播方式投递
        'amqpOrderFanoutPublish' => function() use($dc) {
            /**
             * @var AMQPStreamConnection $connection
             */
            $connection = \Swoolefy\Core\Application::getApp()->get('amqpConnection')->getObject();
            $amqpConfig = new \Common\Library\Amqp\AmqpConfig();
            $amqpConfig->exchangeName = AmqpConfig::AMQP_EXCHANGE_FANOUT_ORDER;
            $amqpFanoutConsumer = new \Common\Library\Amqp\AmqpFanoutQueue($connection, $amqpConfig);
            $amqpFanoutConsumer->setAckHandler(function (\PhpAmqpLib\Message\AMQPMessage $message) {
                echo "Message acked with content " . $message->body . PHP_EOL;
            });
            return $amqpFanoutConsumer;
        },

        // 进程消费fanout投递的数据
        'amqpOrderAddFanoutQueue' => function() use($dc) {
            /**
             * @var AMQPStreamConnection $connection
             */
            $connection = \Swoolefy\Core\Application::getApp()->get('amqpConnection')->getObject();
            $amqpConfig = new \Common\Library\Amqp\AmqpConfig();
            $amqpConfig->exchangeName = AmqpConfig::AMQP_EXCHANGE_FANOUT_ORDER;
            $amqpConfig->queueName = AmqpConfig::AMQP_QUEUE_FANOUT_ORDER_ADD;

            // fanout
            $property = AmqpConfig::AMQP_FANOUT[$amqpConfig->exchangeName][$amqpConfig->queueName];
            $amqpConfig->type = $property['type'];
            $amqpConfig->bindingKey = $property['binding_key'];
            $amqpConfig->routingKey = $property['routing_key'];
            $amqpConfig->passive = $property['passive'];
            $amqpConfig->durable = $property['durable'];
            $amqpConfig->exclusive = $property['exclusive'];
            $amqpConfig->autoDelete = $property['auto_delete'];

            $amqpFanoutConsumer = new \Common\Library\Amqp\AmqpFanoutQueue($connection, $amqpConfig);
            return $amqpFanoutConsumer;
        },

        // 进程消费fanout投递的数据
        'amqpExportFanoutQueue' => function() use($dc) {
            /**
             * @var AMQPStreamConnection $connection
             */
            $connection = \Swoolefy\Core\Application::getApp()->get('amqpConnection')->getObject();
            $amqpConfig = new \Common\Library\Amqp\AmqpConfig();
            $amqpConfig->exchangeName = AmqpConfig::AMQP_EXCHANGE_FANOUT_ORDER;
            $amqpConfig->queueName = AmqpConfig::AMQP_QUEUE_FANOUT_ORDER_EXPORT;

            // fanout
            $property = AmqpConfig::AMQP_FANOUT[$amqpConfig->exchangeName][$amqpConfig->queueName];
            $amqpConfig->type = $property['type'];
            $amqpConfig->bindingKey = $property['binding_key'];
            $amqpConfig->routingKey = $property['routing_key'];
            $amqpConfig->passive = $property['passive'];
            $amqpConfig->durable = $property['durable'];
            $amqpConfig->exclusive = $property['exclusive'];
            $amqpConfig->autoDelete = $property['auto_delete'];

            $amqpFanoutConsumer = new \Common\Library\Amqp\AmqpFanoutQueue($connection, $amqpConfig);
            return $amqpFanoutConsumer;
        },

        // topic发布与消费实例
        'orderAddTopicQueue' => function() use($dc) {
            /**
             * @var AMQPStreamConnection $connection
             */
            $connection = \Swoolefy\Core\Application::getApp()->get('amqpConnection')->getObject();
            $amqpConfig = new \Common\Library\Amqp\AmqpConfig();
            $amqpConfig->exchangeName = AmqpConfig::AMQP_EXCHANGE_TOPIC_ORDER;
            $amqpConfig->queueName    = AmqpConfig::AMQP_QUEUE_TOPIC_ORDER_ADD;

            $property = AmqpConfig::AMQP_TOPIC[$amqpConfig->exchangeName][$amqpConfig->queueName];
            $amqpConfig->type = $property['type'];
            $amqpConfig->bindingKey = $property['binding_key'];
            $amqpConfig->routingKey = $property['routing_key'];
            $amqpConfig->passive = $property['passive'];
            $amqpConfig->durable = $property['durable'];
            $amqpConfig->exclusive = $property['exclusive'];
            $amqpConfig->autoDelete = $property['auto_delete'];
            $amqpConfig->consumerTag = $property['consumer_tag'];

            $AmqpTopicPublish = new \Common\Library\Amqp\AmqpTopicQueue($connection, $amqpConfig);
//            $AmqpTopicPublish->setAckHandler(function (\PhpAmqpLib\Message\AMQPMessage $message) {
//                echo "Message acked with content " . $message->body . PHP_EOL;
//            });
            return $AmqpTopicPublish;
        },

        // topic发布与消费实例
        'orderDelayTopicQueue' => function() use($dc) {
            /**
             * @var AMQPStreamConnection $connection
             */
            $connection = \Swoolefy\Core\Application::getApp()->get('amqpConnection')->getObject();
            $amqpConfig = new \Common\Library\Amqp\AmqpConfig();
            $amqpConfig->exchangeName = AmqpConfig::AMQP_EXCHANGE_TOPIC_ORDER;

            // 延迟队列
            $amqpConfig->queueName    = AmqpConfig::AMQP_QUEUE_TOPIC_ORDER_ADD_DELAY;

            $property = AmqpConfig::AMQP_TOPIC[$amqpConfig->exchangeName][$amqpConfig->queueName];
            $amqpConfig->type = $property['type'];
            $amqpConfig->bindingKey = $property['binding_key'];
            $amqpConfig->routingKey = $property['routing_key'];
            $amqpConfig->passive = $property['passive'];
            $amqpConfig->durable = $property['durable'];
            $amqpConfig->exclusive = $property['exclusive'];
            $amqpConfig->autoDelete = $property['auto_delete'];
            $amqpConfig->arguments = $property['arguments'];

            $amqpTopicPublish = new \Common\Library\Amqp\AmqpDelayTopicQueue($connection, $amqpConfig);
//            $AmqpTopicPublish->setAckHandler(function (\PhpAmqpLib\Message\AMQPMessage $message) {
//                echo "Message acked with content " . $message->body . PHP_EOL;
//            });
            return $amqpTopicPublish;
        },

        // kafka-group1_producer生产者
        'kafka_topic_order_group1_producer' => function() use($dc) {
            $kafkaConf = KafkaConfig::KAFKA_TOPICS[KafkaConfig::KAFKA_TOPIC_ORDER1];
            $producer = new \Common\Library\Kafka\Producer($dc['kafka_broker_list'], $kafkaConf['topic_name']);
            if(\Swoolefy\Core\SystemEnv::isDevEnv()) {}
            $producer->setGlobalProperty($kafkaConf['producer_global_property']);
            $producer->setTopicProperty($kafkaConf['producer_topic_property']);
            return $producer;
        },

        // kafka-group1_producer 消费者
        'kafka_topic_order_group1_consumer' => function() use($dc) {
            $kafkaConf = KafkaConfig::KAFKA_TOPICS[KafkaConfig::KAFKA_TOPIC_ORDER1];
            $consumer = new \Common\Library\Kafka\Consumer($dc['kafka_broker_list'], $kafkaConf['topic_name']);
            $consumer->setGroupId($kafkaConf['group_id']);
            if(\Swoolefy\Core\SystemEnv::isDevEnv()) {}
            $consumer->setGlobalProperty($kafkaConf['consumer_global_property']);
            $consumer->setTopicProperty($kafkaConf['consumer_topic_property']);
            return $consumer;
        },

    ],

//    'catch_handle' => function(\Swoole\Http\Request $request, \Swoole\Http\Response $response) {
//        $response->end(json_encode(['code'=>-1,'msg'=>'系统维护中']));
//    }
];
