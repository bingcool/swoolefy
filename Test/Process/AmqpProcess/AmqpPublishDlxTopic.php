<?php

declare(strict_types=1);

namespace Test\Process\AmqpProcess;

use Swoolefy\Core\Process\AbstractProcess;
use Swoolefy\Support\Amqp\AmqpProducer;

/**
 * Topic 延迟生产。
 *
 * 消息先进入延迟队列，10 秒后再作为死信投到 topic 死信交换机。
 *
 * 生产侧两个键都作用在「延迟交换机」：
 * - bindingKey = order.delay.topic.#：延迟队列的匹配模式。# 表示后面可以有零个或多个词。
 * - routingKey = order.delay.topic.demo：打在这一次消息上的具体键，能被上面的模式匹配，消息才进延迟队列。
 *   publish() 的第二个参数就是这一次的 routingKey，和 bindingKey 不是同一个字符串。
 *
 * 10 秒到期后，Broker 改用 x-dead-letter-routing-key（order.dlx.topic.demo）投到死信交换机。
 * 死信消费进程的 bindingKey 是 order.dlx.topic.#，匹配的是这个新键，不是 order.delay.topic.demo。
 */
class AmqpPublishDlxTopic extends AbstractProcess
{
    public function run()
    {
        $producer = new AmqpProducer(
            exchange: AmqpConst::DELAY_EXCHANGE_TOPIC,
            exchangeType: 'topic',
            queue: AmqpConst::DELAY_QUEUE_TOPIC,
            // 延迟队列的绑定模式。Topic 用 * / #，不必和某一次消息的 routingKey 写成同一个字符串。
            bindingKey: AmqpConst::DELAY_BINDING_TOPIC,
            // 默认 routingKey。下面 publish() 的第二个参数会作为这一次消息的键。
            routingKey: AmqpConst::DELAY_ROUTING_TOPIC,
            queueArguments: AmqpConst::delayQueueArguments(
                AmqpConst::DLX_EXCHANGE_TOPIC,
                // 到期后的新 routingKey，要能被死信队列的 bindingKey 匹配。
                AmqpConst::DLX_ROUTING_TOPIC,
            ),
            deadLetterExchangeType: 'topic',
        );
        while (true) {
            $producer->publish(
                'topic dlx publishedAt:' . date('Y-m-d H:i:s'),
                // 这一次消息的 routingKey，匹配延迟队列的 order.delay.topic.#。
                AmqpConst::DELAY_ROUTING_TOPIC,
            );
            \Swoole\Coroutine::sleep(10);
        }
    }
}
