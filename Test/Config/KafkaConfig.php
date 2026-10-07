<?php

declare(strict_types=1);

namespace Test\Config;

/**
 * Kafka 公共 librdkafka 默认属性（不含 Topic / consumer group）。
 *
 * 各 Topic 在 Config/component/kafka/producer|consumer/*.php 用局部变量声明；
 * 差异配置传入 producerGlobalProperty([]) / consumerGlobalProperty([]) 覆盖。
 *
 * @see https://github.com/confluentinc/librdkafka/blob/master/CONFIGURATION.md
 */
class KafkaConfig
{
    private const DEFAULT_PRODUCER_GLOBAL_PROPERTY = [
        'enable.idempotence' => 0,
        'message.send.max.retries' => 5,
    ];

    private const DEFAULT_PRODUCER_TOPIC_PROPERTY = [];

    private const DEFAULT_CONSUMER_GLOBAL_PROPERTY = [
        'enable.auto.commit' => 1,
        'auto.commit.interval.ms' => 200,
        'auto.offset.reset' => 'earliest',
        'session.timeout.ms' => 45 * 1000,
        'max.poll.interval.ms' => 600 * 1000,
    ];

    private const DEFAULT_CONSUMER_TOPIC_PROPERTY = [];

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    public static function producerGlobalProperty(array $override = []): array
    {
        return array_replace(self::DEFAULT_PRODUCER_GLOBAL_PROPERTY, $override);
    }

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    public static function producerTopicProperty(array $override = []): array
    {
        return array_replace(self::DEFAULT_PRODUCER_TOPIC_PROPERTY, $override);
    }

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    public static function consumerGlobalProperty(array $override = []): array
    {
        return array_replace(self::DEFAULT_CONSUMER_GLOBAL_PROPERTY, $override);
    }

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    public static function consumerTopicProperty(array $override = []): array
    {
        return array_replace(self::DEFAULT_CONSUMER_TOPIC_PROPERTY, $override);
    }
}
