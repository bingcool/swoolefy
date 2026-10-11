<?php

declare(strict_types=1);

namespace Swoolefy\Support\Amqp;

use Swoolefy\Support\Amqp\Dto\ReceivedMessageDto;
use Swoolefy\Worker\AbstractWorkerProcess;

/**
 * Daemon / Cron 常驻进程里的 AMQP 消费。循环和退出沿用 AbstractWorkerProcess。
 * 同一队列的多个进程会分摊消息，每个进程单独建连。
 * onMessage() 抛异常时不 ack，同一条会再交给 onMessage()。
 *
 * bindingKey 是队列绑到交换机的规则。routingKey 只在 bindingKey 为空时顶替它，消费端不用它发消息。
 * 含义与 AmqpConsumerProcessHandler 相同。
 */
abstract class AmqpConsumerWorkerProcessHandler extends AbstractWorkerProcess
{
    use AmqpConsumerFields;

    protected string $exchange = '';

    protected string $exchangeType = 'direct';

    protected string $queue = '';

    /**
     * 队列绑到交换机的键。direct 是精确值，topic 是 * / # 模式，fanout 留空。
     */
    protected string $bindingKey = '';

    /**
     * bindingKey 为空时才当作绑定键。消费端不用它给消息指定路由。
     */
    protected string $routingKey = '';

    /**
     * @var array<string, mixed>
     */
    protected array $queueArguments = [];

    /**
     * @var array<string, mixed>
     */
    protected array $exchangeArguments = [];

    protected string $consumerTag = '';

    private AmqpConsumeSession $session;

    private ?ReceivedMessageDto $pending = null;

    abstract public function onMessage(ReceivedMessageDto $message): void;

    protected function onInit()
    {
        parent::onInit();
        $this->session = new AmqpConsumeSession($this->amqpTopology());
    }

    protected function loopHandle()
    {
        if ($this->waitToExit) {
            return;
        }
        if ($this->pending === null) {
            try {
                $this->pending = $this->session->poll();
            } catch (\Throwable $e) {
                $this->onHandleException($e);
                \Swoole\Coroutine::sleep(0.5);

                return;
            }
            if ($this->pending === null) {
                \Swoole\Coroutine::sleep(0.2);

                return;
            }
        }
        if ($this->waitToExit) {
            return;
        }
        try {
            $this->onMessage($this->pending);
        } catch (\Throwable $e) {
            $this->onHandleException($e);
            \Swoole\Coroutine::sleep(0.5);

            return;
        }
        try {
            $this->session->ack();
            $this->pending = null;
        } catch (\Throwable $e) {
            $this->pending = null;
            $this->onHandleException($e);
            \Swoole\Coroutine::sleep(0.5);
        }
    }
}
