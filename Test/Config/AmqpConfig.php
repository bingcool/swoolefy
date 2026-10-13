<?php

declare(strict_types=1);

namespace Test\Config;

/**
 * AMQP 公共声明参数（不含具体交换机 / 队列名）。
 *
 * Support\Amqp 的生产者和消费者读取这里的属性。
 * 交换机、队列、routing key 写在业务类上。
 * 连接参数：Config/dc.php 的 amqp_connection，来自 .env AMQP_HOST、AMQP_PORT、AMQP_USER、AMQP_PASSWORD、AMQP_VHOST。
 */
class AmqpConfig
{
    /**
     * 延迟队列 arguments 里 x-dead-letter-exchange 可引用此交换机名。
     * 类型必须和 Broker 上已有的同名交换机一致，当前是 topic。
     */
    public const COMMON_DLX_EXCHANGE = 'common_dlx_exchange';

    public const COMMON_DLX_EXCHANGE_TYPE = 'topic';

    /**
     * 交换机声明。
     *
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    public static function exchangeProperty(array $override = []): array
    {
        return array_replace([
            'passive' => false,
            'durable' => true,
            'auto_delete' => false,
            'internal' => false,
            'arguments' => [],
        ], $override);
    }

    /**
     * 队列声明。队列默认持久化，不独占，不自动删除。
     *
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    public static function queueProperty(array $override = []): array
    {
        return array_replace([
            'passive' => false,
            'durable' => true,
            'exclusive' => false,
            'auto_delete' => false,
            'arguments' => [],
        ], $override);
    }

    /**
     * 消费参数。prefetch_count 固定为 1：一次只投递一条未确认消息，Support\Amqp 消费端写别的值也不生效。
     * consumer_tag 是前缀，进程里会再拼上 pid。
     *
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    public static function consumeProperty(array $override = []): array
    {
        return array_replace([
            'prefetch_count' => 1,
            'prefetch_size' => 0,
            'global' => false,
            'no_local' => false,
            'exclusive' => false,
            'consumer_tag' => 'consumer',
        ], $override);
    }

    /**
     * 发送参数。delivery_mode=2 为持久化消息。
     * confirm_timeout 是等待 Broker 确认的秒数，不会写进消息属性。
     *
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    public static function publishProperty(array $override = []): array
    {
        return array_replace([
            'content_type' => 'text/plain',
            'delivery_mode' => 2,
            'confirm_timeout' => 5.0,
        ], $override);
    }
}
