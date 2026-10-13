<?php

declare(strict_types=1);

namespace __APP_NAMESPACE__\Config;

/**
 * Kafka 公共 librdkafka 默认属性（不含 Topic 名、consumer group）。
 *
 * Support\Kafka 的生产者和消费者读取这里的 librdkafka 属性。
 * Topic、group.id 写在业务类上。
 * Broker 列表：Config/dc.php 的 kafka_broker_list，来自 .env KAFKA_BROKER_LIST（逗号分隔）。
 *
 * @see https://github.com/confluentinc/librdkafka/blob/master/CONFIGURATION.md
 */
class KafkaConfig
{
    /**
     * Producer 全局属性。
     * enable.idempotence=0：关闭幂等（开启时需配合 acks=all 等约束）。
     * message.send.max.retries：发送失败重试次数。
     *
     * @var array<string, mixed>
     */
    private const DEFAULT_PRODUCER_GLOBAL_PROPERTY = [
        'enable.idempotence' => 0,
        'message.send.max.retries' => 5,
    ];

    /**
     * Producer Topic 级属性（如 request.required.acks）。默认空，按 Topic 在组件里覆盖。
     *
     * @var array<string, mixed>
     */
    private const DEFAULT_PRODUCER_TOPIC_PROPERTY = [];

    /**
     * Consumer 全局属性。
     * enable.auto.commit=0：Support\Kafka 由框架显式提交 offset，KafkaConsumeSession 强制关闭自动提交，配成 1 只会打 warning。
     *   提交时机在消费 handler 的 $commitMode 里选：AFTER_HANDLED（默认）/ AFTER_HANDLED_ASYNC / BEFORE_HANDLE，见 KafkaCommitMode。
     * auto.offset.reset=earliest：无已提交 offset 时从最早消息开始。
     * session.timeout.ms / max.poll.interval.ms：心跳与两次 poll 的最大间隔，超时会被踢出组。
     *
     * @var array<string, mixed>
     */
    private const DEFAULT_CONSUMER_GLOBAL_PROPERTY = [
        'enable.auto.commit' => 0,
        'auto.offset.reset' => 'earliest',
        'session.timeout.ms' => 45 * 1000,
        'max.poll.interval.ms' => 600 * 1000,
    ];

    /**
     * Consumer Topic 级属性。默认空，按消费组在组件里覆盖。
     *
     * @var array<string, mixed>
     */
    private const DEFAULT_CONSUMER_TOPIC_PROPERTY = [];

    /**
     * Producer 全局属性；传入 $override 覆盖默认键。
     *
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    public static function producerGlobalProperty(array $override = []): array
    {
        return array_replace(self::DEFAULT_PRODUCER_GLOBAL_PROPERTY, $override);
    }

    /**
     * Producer Topic 级属性。
     *
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    public static function producerTopicProperty(array $override = []): array
    {
        return array_replace(self::DEFAULT_PRODUCER_TOPIC_PROPERTY, $override);
    }

    /**
     * Consumer 全局属性。
     *
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    public static function consumerGlobalProperty(array $override = []): array
    {
        return array_replace(self::DEFAULT_CONSUMER_GLOBAL_PROPERTY, $override);
    }

    /**
     * Consumer Topic 级属性。
     *
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    public static function consumerTopicProperty(array $override = []): array
    {
        return array_replace(self::DEFAULT_CONSUMER_TOPIC_PROPERTY, $override);
    }
}
