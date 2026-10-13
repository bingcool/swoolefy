<?php

declare(strict_types=1);

namespace Swoolefy\Support\Amqp;

/**
 * 消息已经发出，但没有等到 Broker 的 ack / nack（确认超时或连接中断）。
 * Broker 可能已经收下，也可能没有。调用方不要直接重发，否则可能重复；需要重发时消费端要按业务键幂等。
 */
final class AmqpOutcomeUnknownException extends AmqpException
{
}
