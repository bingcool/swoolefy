<?php

declare(strict_types=1);

namespace Test\Process\AmqpProcess;

use Swoolefy\Core\Process\AbstractProcess;
use Swoolefy\Support\Amqp\AmqpProducer;

/**
 * Fanout 延迟生产。
 *
 * 消息先进入延迟队列，10 秒后再投到 fanout 死信交换机。Fanout 复制给所有绑定队列，不看键。
 *
 * 生产侧：
 * - 不传 bindingKey、routingKey。Fanout 忽略它们，延迟队列只要绑在延迟交换机上就能收到每一条消息。
 * - 也不写 x-dead-letter-routing-key。到期后死信交换机同样忽略 routingKey，
 *   凡是绑在死信交换机上的队列都会收到。
 */
class AmqpPublishDlxFanout extends AbstractProcess
{
    public function run()
    {
        $producer = new AmqpProducer(
            exchange: AmqpConst::DELAY_EXCHANGE_FANOUT,
            exchangeType: 'fanout',
            queue: AmqpConst::DELAY_QUEUE_FANOUT,
            // Fanout 不比较键，所以这里不传 bindingKey / routingKey。
            queueArguments: AmqpConst::delayQueueArguments(AmqpConst::DLX_EXCHANGE_FANOUT),
            deadLetterExchangeType: 'fanout',
        );
        while (true) {
            $producer->publish('fanout dlx publishedAt:' . date('Y-m-d H:i:s'));
            \Swoole\Coroutine::sleep(10);
        }
    }
}
