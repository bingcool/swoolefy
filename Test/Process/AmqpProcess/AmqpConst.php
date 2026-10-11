<?php

namespace Test\Process\AmqpProcess;

/**
 * 测试用交换机和队列名。连接账号在 Config/dc.php，不写在这里。
 *
 * bindingKey 是队列绑到交换机的规则，routingKey 是打在某一条消息上的键。
 * 生产：publish 使用 routingKey，交换机再拿它去比各个队列的 bindingKey。
 * 消费：进程只设置 bindingKey，决定这个队列接收哪些消息；它自己不发消息。
 *
 * 死信这条链路有两段键，不要混用：
 * 1. 消息先按延迟交换机的 routingKey / bindingKey 进入延迟队列，在那里等 10 秒。
 * 2. 到期后 Broker 改用 x-dead-letter-routing-key 作为新的 routingKey，投到死信交换机。
 *    死信消费进程的 bindingKey 要匹配的是这个新键，不是延迟队列的键。
 *    没写 x-dead-letter-routing-key 时，死信仍沿用消息原来的 routingKey。Fanout 忽略该键。
 */
final class AmqpConst
{
    /** Direct 业务交换机。routingKey 必须和队列 bindingKey 完全一致。 */
    public const EXCHANGE_DIRECT = 'order_exchange_direct';

    /** Direct 业务队列。AmqpPublish 声明并绑定，AmqpConsumer 从这里消费。 */
    public const QUEUE_DIRECT_ADD = 'order_add_queue_direct';

    /**
     * Direct 业务键 order-direct-add。
     * 生产：作为 routingKey 打在消息上，同时作为 bindingKey 把 QUEUE_DIRECT_ADD 绑到 EXCHANGE_DIRECT。
     * 消费：AmqpConsumer 的 bindingKey 也是它。两者必须相同，消息才进得了这个队列。
     */
    public const ROUTING_DIRECT_ADD = 'order-direct-add';

    /**
     * 接口 /api/amqp/publish-delay-direct 使用的延迟队列，不是下面那组 10 秒死信测试进程。
     * 消息在这个队列停 3 秒后，按 ROUTING_DIRECT_ADD 死信到 common_dlx_exchange。
     */
    public const QUEUE_DIRECT_DELAY = 'order_add_queue_delay_direct';

    /**
     * 上面这个延迟队列的键 order_delay。
     * 生产时 bindingKey 和 routingKey 都用它，消息才能先进入延迟队列。
     * 它不是死信之后的键；到期后的 routingKey 是 ROUTING_DIRECT_ADD。
     */
    public const ROUTING_DIRECT_DELAY = 'order_delay';

    /** Fanout 业务交换机。忽略 bindingKey 和 routingKey，绑定上的每个队列都收到同一条消息。 */
    public const EXCHANGE_FANOUT = 'order_exchange_fanout';

    /** Fanout 消费队列 1。bindingKey 留空，AmqpConsumerFanout 使用。 */
    public const QUEUE_FANOUT_ADD = 'order_add_queue_fanout';

    /** Fanout 消费队列 2。bindingKey 留空，和队列 1 收到的是同一批消息。AmqpConsumerFanout1 使用。 */
    public const QUEUE_FANOUT_EXPORT = 'order_export_queue_fanout';

    /** Topic 业务交换机。用消息 routingKey 去匹配队列 bindingKey 里的 * 和 #。 */
    public const EXCHANGE_TOPIC = 'order_exchange_topic';

    /** Topic 业务队列。AmqpPublishTopic 声明，AmqpConsumerTopic 消费。 */
    public const QUEUE_TOPIC_ADD = 'order_add_queue_topic';

    /**
     * Topic 消费侧 bindingKey：orderSaveEvent.#。
     * # 表示后面可以有零个或多个词。这是队列规则，不是某一条消息的键。
     * AmqpConsumerTopic 只写这个字段，不写 routingKey。
     */
    public const BINDING_TOPIC_ADD = 'orderSaveEvent.#';

    /**
     * Topic 生产侧 routingKey：orderSaveEvent.send。
     * 打在这一次消息上，能被 BINDING_TOPIC_ADD 匹配，消息才进入 QUEUE_TOPIC_ADD。
     * 它和 bindingKey 不是同一个字符串。
     */
    public const ROUTING_TOPIC_SEND = 'orderSaveEvent.send';

    /** 死信测试的延迟时间。消息在延迟队列里停留这么久，然后才进入死信交换机。 */
    public const DELAY_TTL_MS = 10 * 1000;

    /** Direct 延迟交换机。只负责把消息送进延迟队列，10 秒后消息会离开这里。 */
    public const DELAY_EXCHANGE_DIRECT = 'order_delay_exchange_direct';

    /** Direct 延迟队列。没有消费者来拉它，消息靠 TTL 到期后变成死信。 */
    public const DELAY_QUEUE_DIRECT = 'order_delay_queue_direct';

    /**
     * Direct 延迟键 order.delay.direct。
     * 生产：bindingKey 和 routingKey 都用它，消息才能进入 DELAY_QUEUE_DIRECT。
     * 这不是死信消费用的键。到期后改用 DLX_ROUTING_DIRECT。
     */
    public const DELAY_ROUTING_DIRECT = 'order.delay.direct';

    /** Direct 死信交换机。延迟到期后，消息被投到这里。 */
    public const DLX_EXCHANGE_DIRECT = 'order_dlx_exchange_direct';

    /** Direct 死信队列。AmqpConsumerDlxDirect 的 bindingKey 是 DLX_ROUTING_DIRECT。 */
    public const DLX_QUEUE_DIRECT = 'order_dlx_queue_direct';

    /**
     * Direct 死信键 order.dlx.direct。
     * 写入延迟队列的 x-dead-letter-routing-key，是到期之后那一次投递的 routingKey。
     * 死信消费的 bindingKey 必须和它完全一致，不要写成 DELAY_ROUTING_DIRECT。
     */
    public const DLX_ROUTING_DIRECT = 'order.dlx.direct';

    /** Fanout 延迟交换机。忽略键，绑定上的延迟队列都会收到。 */
    public const DELAY_EXCHANGE_FANOUT = 'order_delay_exchange_fanout';

    /** Fanout 延迟队列。不设置 bindingKey / routingKey。 */
    public const DELAY_QUEUE_FANOUT = 'order_delay_queue_fanout';

    /** Fanout 死信交换机。到期后同样忽略 routingKey。 */
    public const DLX_EXCHANGE_FANOUT = 'order_dlx_exchange_fanout';

    /** Fanout 死信队列。AmqpConsumerDlxFanout 的 bindingKey、routingKey 都留空。 */
    public const DLX_QUEUE_FANOUT = 'order_dlx_queue_fanout';

    /** Topic 延迟交换机。用 DELAY_ROUTING_TOPIC 去匹配 DELAY_BINDING_TOPIC。 */
    public const DELAY_EXCHANGE_TOPIC = 'order_delay_exchange_topic';

    /** Topic 延迟队列。绑定模式是 DELAY_BINDING_TOPIC，没有消费者直接拉它。 */
    public const DELAY_QUEUE_TOPIC = 'order_delay_queue_topic';

    /**
     * Topic 延迟队列的 bindingKey：order.delay.topic.#。
     * 这是延迟队列的匹配模式。生产时的 routingKey 是 DELAY_ROUTING_TOPIC，两者不是同一个字符串。
     */
    public const DELAY_BINDING_TOPIC = 'order.delay.topic.#';

    /**
     * Topic 延迟消息的 routingKey：order.delay.topic.demo。
     * 只用于进入延迟队列。到期后不会拿它去匹配死信队列，死信用的是 DLX_ROUTING_TOPIC。
     */
    public const DELAY_ROUTING_TOPIC = 'order.delay.topic.demo';

    /** Topic 死信交换机。 */
    public const DLX_EXCHANGE_TOPIC = 'order_dlx_exchange_topic';

    /** Topic 死信队列。消费侧 bindingKey 是 DLX_BINDING_TOPIC。 */
    public const DLX_QUEUE_TOPIC = 'order_dlx_queue_topic';

    /**
     * Topic 死信队列的 bindingKey：order.dlx.topic.#。
     * 消费进程只写这个模式，不写 routingKey。它匹配 DLX_ROUTING_TOPIC，匹配不到 DELAY_ROUTING_TOPIC。
     */
    public const DLX_BINDING_TOPIC = 'order.dlx.topic.#';

    /**
     * Topic 死信投递的 routingKey：order.dlx.topic.demo。
     * 写入 x-dead-letter-routing-key。onMessage() 里 getRoutingKey() 看到的是它。
     */
    public const DLX_ROUTING_TOPIC = 'order.dlx.topic.demo';

    /**
     * 延迟队列参数。消息在这个队列里停留 10 秒，然后被投到死信交换机。
     *
     * $deadLetterRoutingKey 写入 x-dead-letter-routing-key，是到期之后那一次投递的 routingKey。
     * 它要能被死信消费队列的 bindingKey 匹配上。Fanout 死信交换机忽略它，传空字符串即可。
     *
     * @return array<string, mixed>
     */
    public static function delayQueueArguments(string $deadLetterExchange, string $deadLetterRoutingKey = ''): array
    {
        $arguments = [
            'x-message-ttl' => self::DELAY_TTL_MS,
            'x-dead-letter-exchange' => $deadLetterExchange,
        ];
        if ($deadLetterRoutingKey !== '') {
            $arguments['x-dead-letter-routing-key'] = $deadLetterRoutingKey;
        }

        return $arguments;
    }
}
