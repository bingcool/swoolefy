<?php

declare(strict_types=1);

namespace Test\Process\JobProcess;

use Swoolefy\Support\Amqp\AmqpConsumerProcessHandler;
use Swoolefy\Support\Amqp\AmqpProducer;
use Swoolefy\Support\Amqp\Dto\ReceivedMessageDto;
use Swoolefy\Support\Job\JobComponentFactory;
use Swoolefy\Support\Job\JobEnvelope;
use Test\Module\Job\OrderExportHandler;
use Test\Module\Job\OrderPaidNotifyHandler;
use Test\Process\AmqpProcess\AmqpConst;

/**
 * AMQP Job 信封消费。继承消费进程后只处理 onMessage。
 * 重试时用 AmqpProducer 再发到同一队列。
 */
final class JobAmqpConsumer extends AmqpConsumerProcessHandler
{
    protected string $exchange = AmqpConst::EXCHANGE_DIRECT;

    protected string $exchangeType = 'direct';

    protected string $queue = AmqpConst::QUEUE_DIRECT_ADD;

    protected string $bindingKey = AmqpConst::ROUTING_DIRECT_ADD;

    protected string $routingKey = AmqpConst::ROUTING_DIRECT_ADD;

    protected array $queueArguments = ['x-max-priority' => 10];

    public function onMessage(ReceivedMessageDto $message): void
    {
        $data = json_decode($message->getPayload(), true);
        if (!is_array($data)) {
            return;
        }

        $registry = JobComponentFactory::registry(
            new OrderPaidNotifyHandler(),
            new OrderExportHandler(),
        );
        $runner = JobComponentFactory::runner();
        $exchange = $this->exchange;
        $queue = $this->queue;
        $routingKey = $this->routingKey;
        $runner->runRegistered(
            $registry,
            JobEnvelope::fromArray($data),
            requeue: static function (JobEnvelope $next, int $delayMs) use ($exchange, $queue, $routingKey): void {
                unset($delayMs);
                (new AmqpProducer(
                    exchange: $exchange,
                    exchangeType: 'direct',
                    queue: $queue,
                    bindingKey: $routingKey,
                    routingKey: $routingKey,
                    queueArguments: ['x-max-priority' => 10],
                ))->publish(json_encode($next->toArray(), JSON_UNESCAPED_UNICODE));
            },
            dead: static function (JobEnvelope $failed, string $error): void {
                unset($failed, $error);
            },
        );
    }
}
