<?php

declare(strict_types=1);

use Test\Config\KafkaConfig;

$dc = \Swoolefy\Core\SystemEnv::loadDcEnv();

// topic（文件内变量，避免 reload 时 const/define 重复定义）
$kafkaTopicOrder1 = 'topicOrder1';

// consumer group1
$kafkaTopicOrder1ConsumerGroup1 = 'order_group1';

return [
    // kafka-group1 消费者（同 Topic 其它消费组可在本文件继续追加）
    'kafka_topic_order_group1_consumer' => function () use ($dc, $kafkaTopicOrder1, $kafkaTopicOrder1ConsumerGroup1) {
        $consumer = new \Swoolefy\Library\Kafka\Consumer($dc['kafka_broker_list'], $kafkaTopicOrder1);
        $consumer->setGroupId($kafkaTopicOrder1ConsumerGroup1);
        $consumer->setGlobalProperty(KafkaConfig::consumerGlobalProperty());
        $consumer->setTopicProperty(KafkaConfig::consumerTopicProperty());

        return $consumer;
    },
];
