<?php
namespace Test\Config;

class KafkaConfig
{
    const KAFKA_META_BROKER_LIST = '172.17.0.1:9092';

    // topic name order1
    const KAFKA_TOPIC_ORDER1 = 'topicOrder1';

    // 定义所有的topic的配置
    // 参考配置 https://github.com/confluentinc/librdkafka/blob/master/CONFIGURATION.md
    const KAFKA_TOPICS = [
        self::KAFKA_TOPIC_ORDER1 => [
            'metadata_broker_list' => self::KAFKA_META_BROKER_LIST,
            'topic_name' => self::KAFKA_TOPIC_ORDER1,
            'group_id'   => 'topic_orde_group1',
            // 生产端的全局配置
            'producer_global_property' => [
                'enable.idempotence' => 0,
                'message.send.max.retries' => 5
            ],

            // 生产端的topic配置
            'producer_topic_property' => [],

            // 消费端的全局配置
            'consumer_global_property' => [
                'enable.auto.commit' => 1,
                'auto.commit.interval.ms' => 200,
                'auto.offset.reset' => 'earliest',
                'session.timeout.ms' => 45 * 1000,
                'max.poll.interval.ms' => 600 * 1000
            ],
            // 消费端的topic配置
            'consumer_topic_property' => []
        ]
    ];
}