<?php
// 应用配置
use Common\Library\Amqp\AmqpStreamConnectionFactory;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Test\Config\AmqpConst;

$dc = include 'dc-dev.php';

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

    // 组件
    'components' => [
        // 用户行为记录的日志
        'log' => function($name) {
            if(IS_WORKER_SERVICE) {
                $logger = new \Swoolefy\Util\Log($name);
                $logger->setChannel('application');
                $logger->setLogFilePath(LOG_PATH.'/worker.log');
                return $logger;
            }else {
                $logger = new \Swoolefy\Util\Log($name);
                $logger->setChannel('application');
                $logger->setLogFilePath(LOG_PATH.'/runtime.log');
                return $logger;
            }
        },

        // 系统捕捉异常错误日志
        'error_log' => function($name) {
            $logger = new \Swoolefy\Util\Log($name);
            $logger->setChannel('application');
            $logger->setLogFilePath(LOG_PATH.'/error.log');
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

        'amqpConnection' => function() use($dc) {
            $connection = AmqpStreamConnectionFactory::create(
                $dc['amqp_connection']['host_list'],
                $dc['amqp_connection']['options'],
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
            $amqpConfig->exchangeName = AmqpConst::AMQP_EXCHANGE_DIRECT_ORDER;
            $amqpConfig->queueName    = AmqpConst::AMQP_QUEUE_DIRECT_ORDER_ADD;
            $property = AmqpConst::AMQP_DIRECT[$amqpConfig->exchangeName][$amqpConfig->queueName];
            $amqpConfig->type = $property['type'];
            $amqpConfig->bindingKey = $property['binding_key'];
            $amqpConfig->routingKey = $property['routing_key'];
            $amqpConfig->passive = $property['passive'];
            $amqpConfig->durable = $property['durable'];
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
            $amqpConfig->exchangeName = AmqpConst::AMQP_EXCHANGE_DIRECT_ORDER;
            $amqpConfig->queueName    = AmqpConst::AMQP_QUEUE_DIRECT_ORDER_EXPORT;
            $property = AmqpConst::AMQP_DIRECT[$amqpConfig->exchangeName][$amqpConfig->queueName];
            $amqpConfig->type = $property['type'];
            $amqpConfig->bindingKey = $property['binding_key'];
            $amqpConfig->routingKey = $property['routing_key'];
            $amqpConfig->passive = $property['passive'];
            $amqpConfig->durable = $property['durable'];
            $amqpConfig->autoDelete = $property['auto_delete'];

            $amqpDirect = new \Common\Library\Amqp\AmqpDirectQueue($connection, $amqpConfig);

            return $amqpDirect;
        },


        // 交换机下的fanout模式数据广播方式投递
        'amqpOrderFanoutPublish' => function() use($dc) {
            /**
             * @var AMQPStreamConnection $connection
             */
            $connection = \Swoolefy\Core\Application::getApp()->get('amqpConnection')->getObject();
            $amqpConfig = new \Common\Library\Amqp\AmqpConfig();
            $amqpConfig->exchangeName = AmqpConst::AMQP_EXCHANGE_FANOUT_ORDER;
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
            $amqpConfig->exchangeName = AmqpConst::AMQP_EXCHANGE_FANOUT_ORDER;
            $amqpConfig->queueName = AmqpConst::AMQP_QUEUE_FANOUT_ORDER_ADD;

            // fanout
            $property = AmqpConst::AMQP_FANOUT[$amqpConfig->exchangeName][$amqpConfig->queueName];
            $amqpConfig->type = $property['type'];
            $amqpConfig->bindingKey = $property['binding_key'];
            $amqpConfig->routingKey = $property['routing_key'];
            $amqpConfig->passive = $property['passive'];
            $amqpConfig->durable = $property['durable'];
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
            $amqpConfig->exchangeName = AmqpConst::AMQP_EXCHANGE_FANOUT_ORDER;
            $amqpConfig->queueName = AmqpConst::AMQP_QUEUE_FANOUT_ORDER_EXPORT;

            // fanout
            $property = AmqpConst::AMQP_FANOUT[$amqpConfig->exchangeName][$amqpConfig->queueName];
            $amqpConfig->type = $property['type'];
            $amqpConfig->bindingKey = $property['binding_key'];
            $amqpConfig->routingKey = $property['routing_key'];
            $amqpConfig->passive = $property['passive'];
            $amqpConfig->durable = $property['durable'];
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
            $amqpConfig->exchangeName = AmqpConst::AMQP_EXCHANGE_TOPIC_ORDER;
            $amqpConfig->queueName    = AmqpConst::AMQP_QUEUE_TOPIC_ORDER_ADD;
            $property = AmqpConst::AMQP_TOPIC[$amqpConfig->exchangeName][$amqpConfig->queueName];
            $amqpConfig->type = $property['type'];
            $amqpConfig->bindingKey = $property['binding_key'];
            $amqpConfig->routingKey = $property['routing_key'];
            $amqpConfig->passive = $property['passive'];
            $amqpConfig->durable = $property['durable'];
            $amqpConfig->autoDelete = $property['auto_delete'];

            $AmqpTopicPublish = new \Common\Library\Amqp\AmqpTopicQueue($connection, $amqpConfig);
            $AmqpTopicPublish->setAckHandler(function (\PhpAmqpLib\Message\AMQPMessage $message) {
                echo "Message acked with content " . $message->body . PHP_EOL;
            });
            return $AmqpTopicPublish;
        },

    ],

//    'catch_handle' => function(\Swoole\Http\Request $request, \Swoole\Http\Response $response) {
//        $response->end(json_encode(['code'=>-1,'msg'=>'系统维护中']));
//    }
];
