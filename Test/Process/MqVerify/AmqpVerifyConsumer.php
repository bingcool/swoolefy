<?php

namespace Test\Process\MqVerify;

use Swoolefy\Support\Amqp\AmqpConsumerProcessHandler;
use Swoolefy\Support\Amqp\AmqpProducer;
use Swoolefy\Support\Amqp\Dto\ReceivedMessageDto;

/**
 * AMQP normal / exception / kill。每个场景一个 direct 队列，10 分钟没有消费者后由 Broker 自动删除。
 * 生产走 AmqpProducer（publisher confirm），启动时生产一次，kill 重启后不再生产。
 */
class AmqpVerifyConsumer extends AmqpConsumerProcessHandler
{
    public const EXCHANGE = 'mq_verify_direct';

    private const QUEUE_ARGUMENTS = ['x-expires' => 600000];

    private string $run = '';

    private string $scenario = '';

    private string $behaviour = '';

    public function run()
    {
        $args = $this->getArgs();
        $this->run = (string) $args['run'];
        $this->scenario = (string) $args['name'];
        $this->behaviour = (string) $args['behaviour'];
        $queue = VerifyScenarios::amqpQueue($this->run, $this->scenario);
        $this->exchange = self::EXCHANGE;
        $this->exchangeType = 'direct';
        $this->queue = $queue;
        $this->bindingKey = $queue;
        $this->routingKey = $queue;
        $this->queueArguments = self::QUEUE_ARGUMENTS;

        if (VerifyState::once($this->run, $this->scenario, 'produced')) {
            try {
                $producer = new AmqpProducer(
                    exchange: self::EXCHANGE,
                    exchangeType: 'direct',
                    routingKey: $queue,
                    queue: $queue,
                    bindingKey: $queue,
                    queueArguments: self::QUEUE_ARGUMENTS,
                );
                foreach (['m1', 'm2', 'm3'] as $payload) {
                    $producer->publish($payload);
                }
                VerifyState::record($this->run, $this->scenario, ['e' => 'produced']);
            } catch (\Throwable $e) {
                VerifyBehaviour::exception($this->run, $this->scenario, $e);
            }
        } else {
            VerifyState::log($this->scenario . ' restarted, pid=' . getmypid());
        }

        parent::run();
    }

    public function onMessage(ReceivedMessageDto $message): void
    {
        VerifyBehaviour::handle($this->run, $this->scenario, $this->behaviour, $message->getPayload(), [
            'redelivered' => $message->isRedelivered(),
        ]);
    }

    public function onHandleException(\Throwable $throwable, array $context = [])
    {
        VerifyBehaviour::exception($this->run, $this->scenario, $throwable);
    }
}
