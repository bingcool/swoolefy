<?php

declare(strict_types=1);

namespace Swoolefy\Support\Kafka;

use Swoolefy\Support\Kafka\Dto\ReceivedMessageDto;

/**
 * 一个消费组 + Topic 上的一次拉取。成功处理后才提交 offset。
 */
final class KafkaConsumeSession
{
    /**
     * @var \RdKafka\KafkaConsumer
     */
    private \RdKafka\KafkaConsumer $consumer;

    /**
     * @var \RdKafka\Message
     */
    private ?\RdKafka\Message $pending = null;

    /**
     * @param string $topic
     * @param string $consumerGroup
     * @param string $brokers
     */
    public function __construct(
        private readonly string $topic,
        private readonly string $consumerGroup,
        private readonly string $brokers = '',
    ) {
        if ($this->topic === '' || $this->consumerGroup === '') {
            throw new KafkaException('kafka consumer topic and group are required');
        }
        if (!extension_loaded('rdkafka')) {
            throw new KafkaException('ext-rdkafka is required');
        }
        $conf = new \RdKafka\Conf();
        KafkaSettings::apply($conf, KafkaSettings::consumerGlobal());
        KafkaSettings::apply($conf, KafkaSettings::consumerTopic());
        $conf->set('metadata.broker.list', KafkaBrokers::resolve($this->brokers));
        $conf->set('group.id', $this->consumerGroup);
        $this->consumer = new \RdKafka\KafkaConsumer($conf);
        $this->consumer->subscribe([$this->topic]);
    }

    /**
     * @return ReceivedMessageDto|null
     */
    public function poll(): ?ReceivedMessageDto
    {
        $message = $this->consumer->consume(1000);
        $err = (int) $message->err;
        $ok = defined('RD_KAFKA_RESP_ERR_NO_ERROR') ? RD_KAFKA_RESP_ERR_NO_ERROR : 0;
        if ($err === $ok) {
            $this->pending = $message;

            return new ReceivedMessageDto(
                payload: (string) $message->payload,
                key: (string) ($message->key ?? ''),
                topic: (string) $message->topic_name,
                partition: (int) $message->partition,
                offset: (int) $message->offset,
            );
        }
        if ($this->isQuiet($err)) {
            return null;
        }
        $reason = function_exists('rd_kafka_err2str') ? rd_kafka_err2str($err) : (string) $err;
        throw new KafkaException('kafka consume failed: ' . $reason);
    }

    /**
     * Commit the pending message.
     */
    public function commit(): void
    {
        if ($this->pending === null) {
            return;
        }
        $this->consumer->commit($this->pending);
        $this->pending = null;
    }

    /**
     * @param int $err
     * @return bool
     */
    private function isQuiet(int $err): bool
    {
        if (defined('RD_KAFKA_RESP_ERR__TIMED_OUT') && $err === RD_KAFKA_RESP_ERR__TIMED_OUT) {
            return true;
        }
        if (defined('RD_KAFKA_RESP_ERR__PARTITION_EOF') && $err === RD_KAFKA_RESP_ERR__PARTITION_EOF) {
            return true;
        }

        return false;
    }
}
