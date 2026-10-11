<?php

declare(strict_types=1);

namespace Swoolefy\Support\Amqp;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use Swoolefy\Support\Amqp\Dto\ReceivedMessageDto;

/**
 * 一个队列上的一次拉取。onMessage() 成功后才 ack。prefetch 来自 AmqpConfig。
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
        $prefetch = (int) ($consume['prefetch_count'] ?? 1);
        if ($prefetch < 1) {
            $prefetch = 1;
        }
        $channel->basic_qos(
            (int) ($consume['prefetch_size'] ?? 0),
            $prefetch,
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
