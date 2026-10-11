<?php

declare(strict_types=1);

namespace Swoolefy\Support\Amqp;

use Swoolefy\Core\Process\AbstractProcess;
use Swoolefy\Support\Amqp\Dto\ReceivedMessageDto;

/**
 * 协议服务里的 AMQP 消费进程。业务实现 onMessage()，并写上交换机和队列。
 * onMessage() 抛异常时不 ack，同一条会再交给 onMessage()。
 *
 * 消费端的 bindingKey、routingKey：
 * - bindingKey：把本进程的 $queue 绑到 $exchange 上的规则。消息能不能进这个队列，只看交换机拿消息的 routingKey 来比这条规则。
 * - routingKey：消费进程不拿它发消息。bindingKey 留空时，才用它当作绑定键。Direct 里两者常写成同一个值；Topic 通常只写 bindingKey。
 * - onMessage() 里的 getRoutingKey() 是这条消息被投递时带的键，不是本类的 $routingKey 字段。
 */
abstract class AmqpConsumerProcessHandler extends AbstractProcess
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

    private bool $running = true;

    abstract public function onMessage(ReceivedMessageDto $message): void;

    public function gracefulShutdownDrain(): void
    {
        $this->running = false;
    }

    public function run()
    {
        $session = new AmqpConsumeSession($this->amqpTopology());
        $pending = null;
        try {
            while ($this->running) {
                if ($pending === null) {
                    try {
                        $pending = $session->poll();
                    } catch (\Throwable $e) {
                        $this->onHandleException($e);
                        \Swoole\Coroutine::sleep(0.5);
                        continue;
                    }
                    if ($pending === null) {
                        \Swoole\Coroutine::sleep(0.2);
                        continue;
                    }
                }
                if (!$this->running) {
                    break;
                }
                try {
                    $this->onMessage($pending);
                } catch (\Throwable $e) {
                    $this->onHandleException($e);
                    \Swoole\Coroutine::sleep(0.5);
                    continue;
                }
                try {
                    $session->ack();
                    $pending = null;
                } catch (\Throwable $e) {
                    $pending = null;
                    $this->onHandleException($e);
                    \Swoole\Coroutine::sleep(0.5);
                }
            }
        } finally {
            $session->close();
        }
    }
}
