<?php

declare(strict_types=1);

namespace Swoolefy\Support\Kafka;

use Swoolefy\Support\Kafka\Dto\ReceivedMessageDto;
use Swoolefy\Support\SupportLog;

/**
 * 一个消费组 + Topic 上的一次拉取，按 KafkaCommitMode 提交 offset。
 *
 * enable.auto.commit 在这里强制为 false：三种提交方式都由框架显式提交，自动提交会绕开 onMessage() 的结果。
 * KafkaConfig 里把它配成 1 时启动打 warning，提示改用 handler 的 $commitMode，不会静默生效。
 */
final class KafkaConsumeSession
{
    /**
     * 同步 commit() 失败时的重试次数。
     */
    private const COMMIT_ATTEMPTS = 3;

    /**
     * @var \RdKafka\KafkaConsumer
     */
    private \RdKafka\KafkaConsumer $consumer;

    /**
     * poll() 拿到、还没提交的消息。
     *
     * @var \RdKafka\Message
     */
    private ?\RdKafka\Message $pending = null;

    /**
     * @param string $topic
     * @param string $consumerGroup
     * @param string $brokers
     * @param string $commitMode KafkaCommitMode::*
     */
    public function __construct(
        private readonly string $topic,
        private readonly string $consumerGroup,
        private readonly string $brokers = '',
        private readonly string $commitMode = KafkaCommitMode::AFTER_HANDLED,
    ) {
        if ($this->topic === '' || $this->consumerGroup === '') {
            throw new KafkaException('kafka consumer topic and group are required');
        }
        KafkaCommitMode::assertValid($this->commitMode);
        if (!extension_loaded('rdkafka')) {
            throw new KafkaException('ext-rdkafka is required');
        }
        $global = KafkaSettings::consumerGlobal();
        $topicProperties = KafkaSettings::consumerTopic();
        $this->warnIfAutoCommitConfigured($global + $topicProperties);

        $conf = new \RdKafka\Conf();
        KafkaSettings::apply($conf, $global);
        KafkaSettings::apply($conf, $topicProperties);
        $conf->set('enable.auto.commit', 'false');
        $conf->set('metadata.broker.list', KafkaBrokers::resolve($this->brokers));
        $conf->set('group.id', $this->consumerGroup);
        if ($this->commitMode === KafkaCommitMode::AFTER_HANDLED_ASYNC) {
            // commitAsync() 的结果在之后的 consume() 里回调到这里
            $conf->setOffsetCommitCb(function ($kafka, $err, $partitions): void {
                $this->onAsyncCommitResult((int) $err, is_array($partitions) ? $partitions : []);
            });
        }
        $this->consumer = new \RdKafka\KafkaConsumer($conf);
        $this->consumer->subscribe([$this->topic]);
    }

    public function commitMode(): string
    {
        return $this->commitMode;
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
     * 同步提交当前消息。
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
     * BEFORE_HANDLE 模式下、onMessage() 之前调用。同步提交，最多重试 COMMIT_ATTEMPTS 次。
     * 返回 false 时消息仍留在 pending，调用方不能调用 onMessage()，稍后再调本方法。
     *
     * @param callable(\Throwable): void $onError
     */
    public function commitBeforeHandle(callable $onError): bool
    {
        return $this->commitSyncWithRetry($onError);
    }

    /**
     * onMessage() 已成功后调用。不论结果如何都不再让业务重跑这一条。
     *
     * AFTER_HANDLED：同步提交，失败只重试提交；仍失败就放弃并返回 false。下一条提交成功时 offset 一并前进；
     * 在那之前重平衡或重启，这条会再投递一次，业务需按 topic + partition + offset 幂等。
     * AFTER_HANDLED_ASYNC：commitAsync() 后立即返回，broker 的应答由 offset_commit_cb 记录。
     * BEFORE_HANDLE：已在 onMessage() 前提交，这里什么也不做。
     *
     * @param callable(\Throwable): void $onError
     */
    public function commitAfterHandled(callable $onError): bool
    {
        if ($this->commitMode === KafkaCommitMode::BEFORE_HANDLE) {
            $this->pending = null;
            return true;
        }
        if ($this->commitMode === KafkaCommitMode::AFTER_HANDLED_ASYNC) {
            if ($this->pending === null) {
                return true;
            }
            try {
                $this->consumer->commitAsync($this->pending);
                return true;
            } catch (\Throwable $exception) {
                $onError($exception);
                return false;
            } finally {
                $this->pending = null;
            }
        }
        if ($this->commitSyncWithRetry($onError)) {
            return true;
        }
        $this->pending = null;

        return false;
    }

    /**
     * BEFORE_HANDLE 模式下 onMessage() 抛异常：offset 已提交，这条不会再投递，留一条可追查的错误日志。
     */
    public function logDropped(ReceivedMessageDto $message, \Throwable $exception): void
    {
        SupportLog::error('kafka', 'kafka message dropped after onMessage failed in before_handle mode', [
            'topic' => $message->getTopic(),
            'consumer_group' => $this->consumerGroup,
            'partition' => $message->getPartition(),
            'offset' => $message->getOffset(),
            'key' => $message->getKey(),
            'error_class' => $exception::class,
            'error_message' => $exception->getMessage(),
        ]);
    }

    /**
     * @param callable(\Throwable): void $onError
     */
    private function commitSyncWithRetry(callable $onError): bool
    {
        for ($attempt = 1; $attempt <= self::COMMIT_ATTEMPTS; $attempt++) {
            try {
                $this->commit();
                return true;
            } catch (\Throwable $exception) {
                $onError($exception);
                if ($attempt < self::COMMIT_ATTEMPTS) {
                    \Swoole\Coroutine::sleep(0.2 * $attempt);
                }
            }
        }

        return false;
    }

    /**
     * @param array<int, \RdKafka\TopicPartition> $partitions
     */
    private function onAsyncCommitResult(int $err, array $partitions): void
    {
        $ok = defined('RD_KAFKA_RESP_ERR_NO_ERROR') ? RD_KAFKA_RESP_ERR_NO_ERROR : 0;
        if ($err === $ok) {
            return;
        }
        $offsets = [];
        foreach ($partitions as $partition) {
            if (is_object($partition) && method_exists($partition, 'getPartition') && method_exists($partition, 'getOffset')) {
                $offsets[] = $partition->getPartition() . ':' . $partition->getOffset();
            }
        }
        SupportLog::warning('kafka', 'kafka async commit failed', [
            'topic' => $this->topic,
            'consumer_group' => $this->consumerGroup,
            'error' => function_exists('rd_kafka_err2str') ? rd_kafka_err2str($err) : (string) $err,
            'offsets' => $offsets,
        ]);
    }

    /**
     * @param array<string, mixed> $properties
     */
    private function warnIfAutoCommitConfigured(array $properties): void
    {
        $value = $properties['enable.auto.commit'] ?? null;
        if ($value === null) {
            return;
        }
        if (!in_array(strtolower(is_bool($value) ? ($value ? 'true' : 'false') : (string) $value), ['1', 'true'], true)) {
            return;
        }
        SupportLog::warning('kafka', 'kafka enable.auto.commit is ignored, set $commitMode on the consumer handler instead', [
            'topic' => $this->topic,
            'consumer_group' => $this->consumerGroup,
            'commit_mode' => $this->commitMode,
        ]);
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
