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
        if (\Swoolefy\Core\SystemEnv::isDevEnv()) {
        }
        $consumer->setGlobalProperty($kafkaConf['consumer_global_property']);
        $consumer->setTopicProperty($kafkaConf['consumer_topic_property']);
        return $consumer;
    }
];