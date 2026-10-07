<?php

/**
 * 示例 Topic — Producer（一个 Topic 一个 producer 文件）。
 *
 * 组件名：kafka_demo_topic_producer
 * 复制本文件并改文件名、Topic 局部变量与组件名，即可增加新 Topic。
 *
 * Broker 列表来自 dc.php / .env KAFKA_BROKER_LIST。
 * librdkafka 默认见 Config\KafkaConfig::producerGlobalProperty()，差异配置传入该方法的数组参数。
 *
 * 勿用全局 const/define 定义 Topic 名，否则 reloadGlobalConf 重复 include 会报错。
 */

declare(strict_types=1);

$dc = \Swoolefy\Core\SystemEnv::loadDcEnv();

/** 本文件 Topic 名（仅本文件使用） */
$kafkaTopicDemo = 'demo_topic';

return [
    'kafka_demo_topic_producer' => static function () use ($dc, $kafkaTopicDemo) {
        $producer = new \Swoolefy\Library\Kafka\Producer($dc['kafka_broker_list'], $kafkaTopicDemo);
        $producer->setGlobalProperty(\__APP_NAMESPACE__\Config\KafkaConfig::producerGlobalProperty());
        $producer->setTopicProperty(\__APP_NAMESPACE__\Config\KafkaConfig::producerTopicProperty());

        return $producer;
    },
];
