<?php

/**
 * Topic topicOrder1 — Consumer 组件（同一 Topic 的多个 group.id 可写在同一文件）。
 *
 * 组件名：kafka_topic_order_group1_consumer
 * setGroupId 即 Kafka consumer group；与 Topic 名无关，同 group 内分区负载均衡。
 */

declare(strict_types=1);

use Test\Config\KafkaConfig;

$dc = \Swoolefy\Core\SystemEnv::loadDcEnv();

$kafkaTopicOrder1 = 'topicOrder1';
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
