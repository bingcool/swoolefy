<?php

declare(strict_types=1);

use Test\Config\KafkaConfig;

$dc = \Swoolefy\Core\SystemEnv::loadDcEnv();

$kafkaTopicOrder1 = 'topicOrder1';

return [
    // kafka-group1_producer 生产者
    'kafka_topic_order_group1_producer' => function () use ($dc, $kafkaTopicOrder1) {
        $producer = new \Swoolefy\Library\Kafka\Producer($dc['kafka_broker_list'], $kafkaTopicOrder1);
        if (\Swoolefy\Core\SystemEnv::isDevEnv()) {
        }
        $producer->setGlobalProperty(KafkaConfig::producerGlobalProperty());
        $producer->setTopicProperty(KafkaConfig::producerTopicProperty());

        return $producer;
    },
];
