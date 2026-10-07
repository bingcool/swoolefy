<?php

declare(strict_types=1);

namespace Test\Config;

use Swoolefy\Library\Amqp\AmqpConfig as LibraryAmqpConfig;

/**
 * AMQP 公共配置（不含具体交换机/队列名）。
 *
 * 业务绑定写在 Config/component/amqp/{direct,fanout,topic}/ 各 php 文件；
 * {@see queueProperty()} 生成属性数组，{@see applyQueueProperty()} 写入 Library AmqpConfig。
 */
class AmqpConfig
{
    /** 延迟/死信队列 arguments 里 x-dead-letter-exchange 可引用此名 */
    public const COMMON_DLX_EXCHANGE = 'common_dlx_exchange';

    /**
     * 队列声明与 bind 的默认项；传入 $override 覆盖 type、binding_key、routing_key、arguments 等。
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
            'consumer_tag' => 'consumer',
            'arguments' => [],
        ], $override);
    }

    /**
     * @param array<string, mixed> $property 须含 type、binding_key、routing_key 及 {@see queueProperty()} 默认字段
     * @param bool $uniqueConsumerTag 为 true 时在 consumer_tag 后追加 _pid_{pid}，避免多进程冲突
     */
    public static function applyQueueProperty(
        LibraryAmqpConfig $config,
        array $property,
        bool $uniqueConsumerTag = false,
    ): void {
        $config->type = $property['type'];
        $config->bindingKey = $property['binding_key'];
        $config->routingKey = $property['routing_key'];
        $config->passive = $property['passive'];
        $config->durable = $property['durable'];
        $config->exclusive = $property['exclusive'];
        $config->autoDelete = $property['auto_delete'];
        $config->arguments = $property['arguments'] ?? [];

        if (!$uniqueConsumerTag) {
            return;
        }

        $tag = (string) ($property['consumer_tag'] ?? 'consumer');
        $config->consumerTag = $tag . '_pid_' . posix_getpid();
    }
}
