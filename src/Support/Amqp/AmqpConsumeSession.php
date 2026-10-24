<?php

declare(strict_types=1);

namespace Swoolefy\Support\Amqp;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use Swoolefy\Support\Amqp\Dto\ReceivedMessageDto;
use Swoolefy\Support\SupportLog;

/**
 * 一个队列上的一次拉取。onMessage() 成功后才 ack。
 *
 * prefetch_count 固定为 1：这里只跟踪一条未确认消息，大于 1 时后到的投递会覆盖前一条，前一条永远不会被 ack。
 * ack 失败时关闭连接，未确认的消息由 Broker 重新投递（redelivered=true），业务需按业务键幂等。
 */
final class AmqpConsumeSession
{
    private AmqpClient $client;

    private ?AMQPMessage $pending = null;

    private ?AMQPChannel $bound = null;

    public function __construct(private readonly AmqpTopology $topology)
    {
        if ($this->topology->exchange === '' || $this->topology->queue === '') {
            throw new AmqpException('amqp consumer exchange and queue are required');
        }
        $this->client = new AmqpClient($this->topology);
    }

    public function poll(float $timeout = 1.0): ?ReceivedMessageDto
    {
        if ($this->pending !== null) {
            return $this->dto($this->pending);
        }
        $this->ensureConsuming();
        $deadline = microtime(true) + $timeout;
        while ($this->pending === null && microtime(true) < $deadline) {
            $left = max(0.1, $deadline - microtime(true));
            try {
                $this->client->consumerChannel()->wait(null, false, $left);
            } catch (AMQPTimeoutException) {
                return null;
            } catch (\Throwable $e) {
                $this->reset();
                throw new AmqpException('amqp consume failed: ' . $e->getMessage(), 0, $e);
            }
        }

        return $this->pending === null ? null : $this->dto($this->pending);
    }

    public function ack(): void
    {
        if ($this->pending === null) {
            return;
        }
        try {
            $this->client->consumerChannel()->basic_ack($this->pending->getDeliveryTag());
            $this->pending = null;
        } catch (\Throwable $e) {
            $this->reset();
            throw new AmqpException('amqp ack failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 拒绝当前消息。requeue=false 时由 Broker 按队列的 x-dead-letter-exchange 转投死信或直接丢弃；
     * 不能 requeue=true 重投毒消息，否则会形成投递死循环。
     */
    public function nack(bool $requeue = false): void
    {
        if ($this->pending === null) {
            return;
        }
        try {
            $this->client->consumerChannel()->basic_nack($this->pending->getDeliveryTag(), false, $requeue);
            $this->pending = null;
        } catch (\Throwable $e) {
            $this->reset();
            throw new AmqpException('amqp nack failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 重试耗尽后记录被丢弃的消息，供后续按业务键补偿。
     */
    public function logDropped(ReceivedMessageDto $message, \Throwable $exception, int $attempts): void
    {
        SupportLog::error('amqp', 'amqp message dropped after retry attempts exhausted', [
            'queue' => $message->getQueue(),
            'exchange' => $message->getExchange(),
            'routing_key' => $message->getRoutingKey(),
            'delivery_tag' => $message->getDeliveryTag(),
            'redelivered' => $message->isRedelivered(),
            'attempts' => $attempts,
            'error_class' => $exception::class,
            'error_message' => $exception->getMessage(),
        ]);
    }

    public function close(): void
    {
        $this->pending = null;
        $this->bound = null;
        $this->client->close();
    }

    private function ensureConsuming(): void
    {
        $channel = $this->client->consumerChannel();
        if ($this->bound === $channel) {
            return;
        }
        $consume = AmqpSettings::consume();
        $channel->basic_qos(
            (int) ($consume['prefetch_size'] ?? 0),
            1,
            (bool) ($consume['global'] ?? false),
        );
        $tag = $this->topology->consumerTag !== ''
            ? $this->topology->consumerTag
            : (string) ($consume['consumer_tag'] ?? 'consumer');
        $tag .= '_pid_' . getmypid();
        $channel->basic_consume(
            $this->topology->queue,
            $tag,
            (bool) ($consume['no_local'] ?? false),
            false,
            (bool) ($consume['exclusive'] ?? false),
            false,
            function (AMQPMessage $message): void {
                if ($this->pending !== null) {
                    // prefetch=1 下不应出现；出现时退回 Broker，不覆盖还没 ack 的那一条
                    $message->nack(true);
                    return;
                }
                $this->pending = $message;
            },
        );
        $this->bound = $channel;
    }

    private function reset(): void
    {
        $this->pending = null;
        $this->bound = null;
        $this->client->close();
    }

    private function dto(AMQPMessage $message): ReceivedMessageDto
    {
        return new ReceivedMessageDto(
            payload: (string) $message->getBody(),
            exchange: (string) $message->getExchange(),
            routingKey: (string) $message->getRoutingKey(),
            queue: $this->topology->queue,
            deliveryTag: (int) $message->getDeliveryTag(),
            redelivered: $message->isRedelivered(),
        );
    }
}
