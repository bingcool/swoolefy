<?php

namespace Test\Process\AmqpProcess;

use Swoolefy\Core\Process\AbstractProcess;
use Swoolefy\Support\Amqp\AmqpProducer;

/**
 * Topic 生产。消息直接进入业务队列，不经过延迟和死信。
 *
 * - bindingKey = orderSaveEvent.#：队列的匹配模式。# 表示后面可以有零个或多个词。
 * - routingKey = orderSaveEvent.send：打在这一次消息上的具体键，能被上面的模式匹配。
 *   这两个字符串不相同。publish() 的第二个参数才是这一次的 routingKey。
 */
class AmqpPublishTopic extends AbstractProcess
{
    public function run()
    {
        $producer = new AmqpProducer(
            exchange: AmqpConst::EXCHANGE_TOPIC,
            exchangeType: 'topic',
            queue: AmqpConst::QUEUE_TOPIC_ADD,
            // 队列绑定模式，不是某一条消息的键。
            bindingKey: AmqpConst::BINDING_TOPIC_ADD,
            // 默认 routingKey。下面 publish() 会再传一次同一个键。
            routingKey: AmqpConst::ROUTING_TOPIC_SEND,
        );
        while (true) {
            $producer->publish(
                'topic publish-' . time(),
                // 这一次消息的 routingKey，要能被 orderSaveEvent.# 匹配。
                AmqpConst::ROUTING_TOPIC_SEND,
            );
            \Swoole\Coroutine::sleep(3);
        }
    }
}
