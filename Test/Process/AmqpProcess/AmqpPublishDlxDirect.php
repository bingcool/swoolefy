<?php

namespace Test\Process\AmqpProcess;

use Swoolefy\Core\Process\AbstractProcess;
use Swoolefy\Support\Amqp\AmqpProducer;

/**
 * Direct 延迟生产。
 *
 * 消息先进入延迟队列，10 秒后再作为死信投到 direct 死信交换机。
 *
 * 生产侧两个键都作用在「延迟交换机」，不是死信交换机：
 * - bindingKey = order.delay.direct：把延迟队列绑到延迟交换机。Direct 要求完全一致。
 * - routingKey = order.delay.direct：打在这一次消息上。和 bindingKey 相同，消息才能进延迟队列。
 *
 * 10 秒到期后，Broker 不再使用上面这个键，而改用 queueArguments 里的
 * x-dead-letter-routing-key（order.dlx.direct）投到死信交换机。
 * 死信消费进程的 bindingKey 必须等于 order.dlx.direct。
 */
class AmqpPublishDlxDirect extends AbstractProcess
{
    public function run()
    {
        $producer = new AmqpProducer(
            exchange: AmqpConst::DELAY_EXCHANGE_DIRECT,
            exchangeType: 'direct',
            queue: AmqpConst::DELAY_QUEUE_DIRECT,
            // 延迟队列绑到延迟交换机的键。Direct 下必须和下面的 routingKey 相同。
            bindingKey: AmqpConst::DELAY_ROUTING_DIRECT,
            // 这次 publish 打在消息上的键，用来匹配延迟队列，不是死信队列的键。
            routingKey: AmqpConst::DELAY_ROUTING_DIRECT,
            queueArguments: AmqpConst::delayQueueArguments(
                AmqpConst::DLX_EXCHANGE_DIRECT,
                // 到期后的新 routingKey。死信消费的 bindingKey 要等于它。
                AmqpConst::DLX_ROUTING_DIRECT,
            ),
            deadLetterExchangeType: 'direct',
        );
        while (true) {
            $producer->publish('direct dlx publishedAt:' . date('Y-m-d H:i:s'));
            \Swoole\Coroutine::sleep(10);
        }
    }
}
