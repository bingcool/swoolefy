<?php

declare(strict_types=1);

namespace Swoolefy\Support\Amqp;

use PhpAmqpLib\Message\AMQPMessage;

/**
 * 按交换机发送。连接缺省读 dc.php 的 amqp_connection。
 * 同一次进程里复用连接。publish() 会等到 Broker 确认或超时，超时后不再自动重发。
 * - Broker 返回 nack：抛 AmqpException，消息没有入队，可以重发。
 * - 已发出但确认超时或连接中断：抛 AmqpOutcomeUnknownException，Broker 可能已经收下，直接重发可能重复。
 * - 发出之前就失败（连不上、声明失败）：抛 AmqpException，可以重发。
 *
 * bindingKey 和 routingKey 不是一回事：
 * - bindingKey：把 $queue 绑到 $exchange 上的规则，属于队列，不随某一条消息变化。
 *   传入 $queue 时，生产者会顺便做这次绑定，消费者还没启动，消息也有地方可放。
 * - routingKey：打在这一次消息上的键。交换机拿它和各个队列的 bindingKey 比较，决定进哪些队列。
 *   publish() 的第二个参数是这一次的 routingKey，不传就用构造时的 $routingKey。它不会改队列绑定。
 * - direct：routingKey 必须和队列 bindingKey 完全一致，否则消息进不去。
 * - topic：routingKey 去匹配 bindingKey 里的通配符，* 是一个词，# 是零个或多个词。
 * - fanout：忽略这两个键，绑在这个交换机上的队列都会收到。两者都可以留空。
 */
final class AmqpProducer
{
    private ?AmqpClient $client = null;

    /**
     * @param string $bindingKey 队列绑到交换机的键。direct 要和 routingKey 相同；topic 是匹配模式；fanout 留空
     * @param string $routingKey 默认打在消息上的键。某一条消息可在 publish() 的第二个参数另指定
     * @param array<string, mixed> $queueArguments
     * @param array<string, mixed> $exchangeArguments
     * @param string $deadLetterExchangeType 队列参数里的 x-dead-letter-exchange 按这个类型声明。不传则用 AmqpConfig
     */
    public function __construct(
        private readonly string $exchange,
        private readonly string $exchangeType = 'direct',
        private readonly string $routingKey = '',
        private readonly string $queue = '',
        private readonly string $bindingKey = '',
        private readonly array $queueArguments = [],
        private readonly array $exchangeArguments = [],
        private readonly string $deadLetterExchangeType = '',
    ) {
    }

    /**
     * @param string $routingKey 这一次消息的键。空字符串表示用构造时的 $routingKey。不会修改队列的 bindingKey
     * @param array<string, mixed> $properties 覆盖 AmqpConfig 的消息属性，如 priority、expiration
     */
    public function publish(string $payload, string $routingKey = '', array $properties = []): void
    {
        if ($this->exchange === '') {
            throw new AmqpException('amqp producer exchange is required');
        }
        $this->client ??= new AmqpClient(new AmqpTopology(
            exchange: $this->exchange,
            exchangeType: $this->exchangeType,
            queue: $this->queue,
            bindingKey: $this->bindingKey,
            routingKey: $this->routingKey,
            exchangeArguments: $this->exchangeArguments,
            queueArguments: $this->queueArguments,
            deadLetterExchangeType: $this->deadLetterExchangeType,
        ));
        $publish = AmqpSettings::publish();
        $timeout = (float) ($publish['confirm_timeout'] ?? 10);
        unset($publish['confirm_timeout']);
        $publish = array_replace($publish, $properties);
        if (isset($publish['delivery_mode'])) {
            $publish['delivery_mode'] = (int) $publish['delivery_mode'];
        }
        if (isset($publish['priority'])) {
            $publish['priority'] = (int) $publish['priority'];
        }
        $key = $routingKey !== '' ? $routingKey : $this->routingKey;
        $sent = false;
        $nacked = false;
        try {
            $channel = $this->client->publisherChannel();
            // 没有注册 nack 回调时 php-amqplib 会忽略 nack，Broker 拒收也会被当成成功
            $channel->set_ack_handler(static function (): void {
            });
            $channel->set_nack_handler(static function () use (&$nacked): void {
                $nacked = true;
            });
            $sent = true;
            $channel->basic_publish(new AMQPMessage($payload, $publish), $this->exchange, $key);
            $channel->wait_for_pending_acks($timeout);
        } catch (AmqpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->client->close();
            $this->client = null;
            if ($sent) {
                throw new AmqpOutcomeUnknownException('amqp publish outcome unknown: ' . $e->getMessage(), 0, $e);
            }
            throw new AmqpException('amqp publish failed: ' . $e->getMessage(), 0, $e);
        }
        if ($nacked) {
            throw new AmqpException('amqp publish nacked by broker');
        }
    }
}
