<?php

declare(strict_types=1);

namespace Swoolefy\Support\Kafka;

/**
 * 按 Topic 发送。Broker 缺省读 dc.php 的 kafka_broker_list。
 * 同一次进程里复用连接。produce() 会等到这次发送完成或超时。
 */
final class KafkaProducer
{
    private ?\RdKafka\Producer $producer = null;

    private ?\RdKafka\ProducerTopic $topicHandle = null;

    public function __construct(
        private readonly string $topic,
        private readonly string $brokers = '',
    ) {
    }

    public function produce(string $payload, string $key = ''): void
    {
        if ($this->topic === '') {
            throw new KafkaException('kafka producer topic is required');
        }
        $this->open();
        $this->topicHandle->produce(
            defined('RD_KAFKA_PARTITION_UA') ? RD_KAFKA_PARTITION_UA : -1,
            0,
            $payload,
            $key !== '' ? $key : null,
        );
        $this->producer->poll(0);
        $code = $this->producer->flush(3000);
        if ($code !== (defined('RD_KAFKA_RESP_ERR_NO_ERROR') ? RD_KAFKA_RESP_ERR_NO_ERROR : 0)) {
            $reason = function_exists('rd_kafka_err2str') ? rd_kafka_err2str($code) : (string) $code;
            throw new KafkaException('kafka produce failed: ' . $reason);
        }
    }

    private function open(): void
    {
        if ($this->producer !== null) {
            return;
        }
        if (!extension_loaded('rdkafka')) {
            throw new KafkaException('ext-rdkafka is required');
        }
        $conf = new \RdKafka\Conf();
        KafkaSettings::apply($conf, KafkaSettings::producerGlobal());
        $conf->set('metadata.broker.list', KafkaBrokers::resolve($this->brokers));
        $topicProperties = KafkaSettings::producerTopic();
        $topicConf = self::topicConf($topicProperties);
        if ($topicConf === null) {
            KafkaSettings::apply($conf, $topicProperties);
        }
        $this->producer = new \RdKafka\Producer($conf);
        $this->topicHandle = $topicConf === null
            ? $this->producer->newTopic($this->topic)
            : $this->producer->newTopic($this->topic, $topicConf);
    }

    /**
     * @param array<string, mixed> $properties
     */
    private static function topicConf(array $properties): ?object
    {
        if ($properties === [] || !class_exists(\RdKafka\TopicConf::class)) {
            return null;
        }
        $topicConf = new \RdKafka\TopicConf();
        KafkaSettings::apply($topicConf, $properties);

        return $topicConf;
    }
}
