<?php

declare(strict_types=1);

namespace Test\Process\AmqpProcess;

use Swoolefy\Core\Process\AbstractProcess;
use Swoolefy\Support\Amqp\AmqpProducer;

/**
 * Direct 生产。消息直接进入业务队列，不经过延迟和死信。
 *
 * - bindingKey = order-direct-add：把 QUEUE_DIRECT_ADD 绑到 EXCHANGE_DIRECT。这是队列规则。
 * - routingKey = order-direct-add：打在这一次消息上。Direct 要求它和 bindingKey 完全相同，消息才进得去。
 *
 * publish() 没有传第二个参数，所以用的就是构造时的 routingKey。
 */
class AmqpPublish extends AbstractProcess
{
    public function run()
    {
        $producer = new AmqpProducer(
            exchange: AmqpConst::EXCHANGE_DIRECT,
            exchangeType: 'direct',
            queue: AmqpConst::QUEUE_DIRECT_ADD,
            // 队列绑到交换机的键。
            bindingKey: AmqpConst::ROUTING_DIRECT_ADD,
            // 打在消息上的键。Direct 下必须和 bindingKey 相同。
            routingKey: AmqpConst::ROUTING_DIRECT_ADD,
            queueArguments: ['x-max-priority' => 10],
        );
        while (true) {
            $producer->publish('amqp direct time:' . time());
            \Swoole\Coroutine::sleep(3);
        }
    }
}
