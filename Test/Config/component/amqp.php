<?php

/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | @see https://github.com/bingcool/swoolefy
 * +----------------------------------------------------------------------
 */

use Common\Library\Amqp\AmqpStreamConnectionFactory;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Swoolefy\Core\Application;
use Test\Config\AmqpConfig;
use Test\Config\KafkaConfig;

$dc = \Swoolefy\Core\SystemEnv::loadDcEnv();

return [
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
        $connection = \Test\App::getAmqpConnection();
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
        $amqpConfig->arguments  = $property['arguments'];
        //$amqpConfig->consumerTag = $property['consumer_tag'].'_pid_'.posix_getpid(); // 最好设置成唯一标志

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
        $connection = \Test\App::getAmqpConnection();
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
        $amqpConfig->arguments  = $property['arguments'];
        $amqpConfig->consumerTag = $property['consumer_tag'].'_pid_'.posix_getpid(); // 最好设置成唯一标志

        $amqpDirect = new \Common\Library\Amqp\AmqpDirectQueue($connection, $amqpConfig);

        return $amqpDirect;
    },

    // direct queue 延迟队列
    'orderDelayDirectQueue' => function() use($dc) {
        /**
         * @var AMQPStreamConnection $connection
         */
        $connection = \Test\App::getAmqpConnection();
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
        $amqpConfig->consumerTag = $property['consumer_tag'].'_pid_'.posix_getpid(); // 最好设置成唯一标志

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
        $connection = \Test\App::getAmqpConnection();
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
        $connection = \Test\App::getAmqpConnection();
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
        $amqpConfig->arguments = $property['arguments'];
        $amqpConfig->consumerTag = $property['consumer_tag'].'_pid_'.posix_getpid();

        $amqpFanoutConsumer = new \Common\Library\Amqp\AmqpFanoutQueue($connection, $amqpConfig);
        return $amqpFanoutConsumer;
    },

    // 进程消费fanout投递的数据
    'amqpExportFanoutQueue' => function() use($dc) {
        /**
         * @var AMQPStreamConnection $connection
         */
        $connection = \Test\App::getAmqpConnection();
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
        $amqpConfig->arguments = $property['arguments'];
        $amqpConfig->consumerTag = $property['consumer_tag'].'_pid_'.posix_getpid();

        $amqpFanoutConsumer = new \Common\Library\Amqp\AmqpFanoutQueue($connection, $amqpConfig);
        return $amqpFanoutConsumer;
    },

    // topic发布与消费实例
    'orderAddTopicQueue' => function() use($dc) {
        /**
         * @var AMQPStreamConnection $connection
         */
        $connection = \Test\App::getAmqpConnection();
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
        $amqpConfig->consumerTag = $property['consumer_tag'].'_pid_'.posix_getpid(); // 最好设置成唯一标志

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
        $connection = \Test\App::getAmqpConnection();
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
        $amqpConfig->consumerTag = $property['consumer_tag'].'_pid_'.posix_getpid(); // 最好设置成唯一标志

        $amqpTopicPublish = new \Common\Library\Amqp\AmqpDelayTopicQueue($connection, $amqpConfig);
//            $AmqpTopicPublish->setAckHandler(function (\PhpAmqpLib\Message\AMQPMessage $message) {
//                echo "Message acked with content " . $message->body . PHP_EOL;
//            });
        return $amqpTopicPublish;
    }
];