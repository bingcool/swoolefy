<?php

declare(strict_types=1);

namespace Swoolefy\Support\Amqp;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * 一个进程里的一条连接。声明交换机和队列，断线后下次使用会重连。
 */
final class AmqpClient
{
    private ?AMQPStreamConnection $connection = null;

    private ?AMQPChannel $channel = null;

    private bool $confirmed = false;

    public function __construct(private readonly AmqpTopology $topology)
    {
    }

    public function publisherChannel(): AMQPChannel
    {
        $channel = $this->channel();
        if (!$this->confirmed) {
            $channel->confirm_select();
            $this->confirmed = true;
        }

        return $channel;
    }

    public function consumerChannel(): AMQPChannel
    {
        return $this->channel();
    }

    public function close(): void
    {
        $channel = $this->channel;
        $connection = $this->connection;
        $this->channel = null;
        $this->connection = null;
        $this->confirmed = false;
        if ($channel !== null) {
            try {
                if ($channel->is_open()) {
                    $channel->close();
                }
            } catch (\Throwable) {
            }
        }
        if ($connection !== null) {
            try {
                if ($connection->isConnected()) {
                    $connection->close();
                }
            } catch (\Throwable) {
            }
        }
    }

    private function channel(): AMQPChannel
    {
        if ($this->connection !== null && $this->connection->isConnected() && $this->channel !== null && $this->channel->is_open()) {
            return $this->channel;
        }
        $this->close();
        if (!class_exists(AMQPStreamConnection::class)) {
            throw new AmqpException('php-amqplib/php-amqplib is required');
        }
        $exchange = trim($this->topology->exchange);
        if ($exchange === '') {
            throw new AmqpException('amqp exchange is required');
        }
        try {
            $this->connection = AMQPStreamConnection::create_connection(AmqpNodes::hosts());
            $this->channel = $this->connection->channel();
            $this->declare($this->channel, $exchange);
        } catch (AmqpException $e) {
            $this->close();
            throw $e;
        } catch (\Throwable $e) {
            $this->close();
            throw new AmqpException('amqp connect failed: ' . $e->getMessage(), 0, $e);
        }

        return $this->channel;
    }

    private function declare(AMQPChannel $channel, string $exchange): void
    {
        $type = strtolower(trim($this->topology->exchangeType));
        if (!in_array($type, ['direct', 'fanout', 'topic', 'headers'], true)) {
            throw new AmqpException('amqp exchange type is invalid');
        }
        $exchangeProperty = AmqpSettings::exchange();
        $exchangeArguments = self::arguments($exchangeProperty['arguments'] ?? [], $this->topology->exchangeArguments);
        $channel->exchange_declare(
            $exchange,
            $type,
            (bool) ($exchangeProperty['passive'] ?? false),
            (bool) ($exchangeProperty['durable'] ?? true),
            (bool) ($exchangeProperty['auto_delete'] ?? false),
            (bool) ($exchangeProperty['internal'] ?? false),
            false,
            new AMQPTable($exchangeArguments),
        );

        $queue = trim($this->topology->queue);
        if ($queue === '') {
            return;
        }
        $queueProperty = AmqpSettings::queue();
        $queueArguments = self::arguments($queueProperty['arguments'] ?? [], $this->topology->queueArguments);
        $deadLetter = $queueArguments['x-dead-letter-exchange'] ?? null;
        if (is_string($deadLetter) && $deadLetter !== '' && $deadLetter !== $exchange) {
            $deadLetterType = $this->topology->deadLetterExchangeType !== ''
                ? strtolower(trim($this->topology->deadLetterExchangeType))
                : AmqpSettings::deadLetterExchangeType();
            if (!in_array($deadLetterType, ['direct', 'fanout', 'topic', 'headers'], true)) {
                throw new AmqpException('amqp exchange type is invalid');
            }
            $channel->exchange_declare(
                $deadLetter,
                $deadLetterType,
                false,
                true,
                false,
                false,
                false,
                new AMQPTable([]),
            );
        }
        $channel->queue_declare(
            $queue,
            (bool) ($queueProperty['passive'] ?? false),
            (bool) ($queueProperty['durable'] ?? true),
            (bool) ($queueProperty['exclusive'] ?? false),
            (bool) ($queueProperty['auto_delete'] ?? false),
            false,
            new AMQPTable($queueArguments),
        );
        $bindingKey = $this->topology->bindingKey !== '' ? $this->topology->bindingKey : $this->topology->routingKey;
        $channel->queue_bind($queue, $exchange, $bindingKey);
    }

    /**
     * @param mixed $defaults
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private static function arguments(mixed $defaults, array $override): array
    {
        $base = is_array($defaults) ? $defaults : [];

        return array_replace($base, $override);
    }
}
