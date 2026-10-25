<?php

declare(strict_types=1);

namespace Test\Process\AmqpProcess;

use Swoolefy\Core\Process\AbstractProcess;
use Swoolefy\Support\Amqp\AmqpProducer;

/**
 * Fanout 生产。消息直接广播到业务交换机，不经过延迟和死信。
 *
 * 不传 bindingKey、routingKey，也不传 queue。
 * Fanout 忽略这两个键。队列由消费进程各自绑定，同一个交换机上的每个队列都会收到这条消息。
 */
class AmqpPublishFanout extends AbstractProcess
{
    public function run()
    {
        $producer = new AmqpProducer(
            exchange: AmqpConst::EXCHANGE_FANOUT,
            exchangeType: 'fanout',
        );
        while (true) {
            $producer->publish('fanout publish-' . time());
            \Swoole\Coroutine::sleep(3);
        }
    }
}
