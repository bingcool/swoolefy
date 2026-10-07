<?php

declare(strict_types=1);

namespace __APP_NAMESPACE__\Config;

use Swoolefy\Library\Amqp\AmqpConfig as LibraryAmqpConfig;

/**
 * AMQP 公共配置（不含具体交换机 / 队列名）。
 *
 * 业务绑定写在 Config/component/amqp/{direct,fanout,topic}/ 各 php 文件：
 * - {@see queueProperty()} 生成声明队列、bind 用的属性数组；
 * - {@see applyQueueProperty()} 把该数组写入 Library AmqpConfig。
 *
 * 连接参数在 Config/dc.php 的 amqp_connection（.env AMQP_*），
 * 连接组件见 Config/component/amqp/connection.php（组件名 amqpConnection）。
 */
class AmqpConfig
{
    /**
     * 延迟 / 死信队列 arguments 里 x-dead-letter-exchange 可引用此交换机名。
     * 对应死信交换机需在 Broker 侧已声明，或由业务组件另行声明。
     */
    public const COMMON_DLX_EXCHANGE = 'common_dlx_exchange';

    /**
     * 队列声明与 bind 的默认项。
     *
     * 常用覆盖键：
     * - type：AMQPExchangeType::DIRECT | FANOUT | TOPIC
     * - binding_key：队列绑到交换机的 key（Topic 支持 * / # 通配）
     * - routing_key：发布默认路由；Topic 发布时常在消息上单独指定，此处可留空
     * - consumer_tag：消费者标识；多进程建议配合 applyQueueProperty 的 uniqueConsumerTag
     * - arguments：如 x-message-ttl、x-dead-letter-exchange、x-max-priority
     *
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    public static function queueProperty(array $override = []): array
    {
        return array_replace([
            'passive' => false,       // true：只检查队列已存在，不创建
            'durable' => true,        // 队列持久化，Broker 重启后仍在
            'exclusive' => false,     // true：连接独占，连接断开即删除
            'auto_delete' => false,   // true：最后一个消费者断开后删除队列
            'consumer_tag' => 'consumer',
            'arguments' => [],
        ], $override);
    }

    /**
     * 将 queueProperty() 结果写入 Library AmqpConfig。
     *
     * $property 须含 type、binding_key、routing_key，以及 queueProperty() 的默认字段。
     * exchangeName / queueName 由调用方在 apply 之前单独赋值。
     *
     * @param array<string, mixed> $property
     * @param bool $uniqueConsumerTag 为 true 时在 consumer_tag 后追加 _pid_{pid}，避免多进程 tag 冲突
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
