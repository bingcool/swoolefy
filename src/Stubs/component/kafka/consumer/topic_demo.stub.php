<?php

/**
 * 示例 Topic — Consumer（同一 Topic 的多个 group.id 写在同一文件）。
 *
 * 组件名：kafka_demo_topic_consumer
 * setGroupId 即 Kafka consumer group；与 Topic 名无关，同 group 内分区负载均衡。
 * 同 Topic 其它消费组：在本文件 return 中再追加一个组件闭包即可。
 *
 * Broker 列表来自 dc.php / .env KAFKA_BROKER_LIST。
 * librdkafka 默认见 Config\KafkaConfig::consumerGlobalProperty()。
 *
 * 勿用全局 const/define 定义 Topic 或 group，否则 reloadGlobalConf 重复 include 会报错。
 */

declare(strict_types=1);

$dc = \Swoolefy\Core\SystemEnv::loadDcEnv();

/** 与 producer 文件中的 Topic 名保持一致 */
$kafkaTopicDemo = 'demo_topic';

/** consumer group.id；不同业务组使用不同 group */
$kafkaTopicDemoConsumerGroup = 'demo_group';

return [
    'kafka_demo_topic_consumer' => static function () use ($dc, $kafkaTopicDemo, $kafkaTopicDemoConsumerGroup) {
        $consumer = new \Swoolefy\Library\Kafka\Consumer($dc['kafka_broker_list'], $kafkaTopicDemo);
        $consumer->setGroupId($kafkaTopicDemoConsumerGroup);
        $consumer->setGlobalProperty(\__APP_NAMESPACE__\Config\KafkaConfig::consumerGlobalProperty());
        $consumer->setTopicProperty(\__APP_NAMESPACE__\Config\KafkaConfig::consumerTopicProperty());

        return $consumer;
    },
];
