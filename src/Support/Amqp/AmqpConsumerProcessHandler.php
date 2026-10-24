<?php

declare(strict_types=1);

namespace Swoolefy\Support\Amqp;

use Swoolefy\Core\Process\AbstractProcess;
use Swoolefy\Support\Amqp\Dto\ReceivedMessageDto;
use Swoolefy\Support\ConsumeRetryLimit;

/**
 * 协议服务里的 AMQP 消费进程。业务实现 onMessage()，并写上交换机和队列。
 * onMessage() 抛异常时本地重试同一条，重试之间指数退避；达到 $maxRetryAttempts（默认 5）后记日志并
 * nack(requeue=false)，由队列的 x-dead-letter-exchange 转投死信或丢弃，避免毒消息卡住队列。
 * 设为 0 或负数保持无限重试。退避参数见 \Swoolefy\Support\ConsumeRetryLimit。
 *
 * 消费端的 bindingKey、routingKey：
 * - bindingKey：把本进程的 $queue 绑到 $exchange 上的规则。消息能不能进这个队列，只看交换机拿消息的 routingKey 来比这条规则。
 * - routingKey：消费进程不拿它发消息。bindingKey 留空时，才用它当作绑定键。Direct 里两者常写成同一个值；Topic 通常只写 bindingKey。
 * - onMessage() 里的 getRoutingKey() 是这条消息被投递时带的键，不是本类的 $routingKey 字段。
 */
abstract class AmqpConsumerProcessHandler extends AbstractProcess
{
    use AmqpConsumerFields;
    use ConsumeRetryLimit;

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
                    $this->resetConsumeFailures();
                }
                if (!$this->running) {
                    break;
                }
                try {
                    $this->onMessage($pending);
                } catch (\Throwable $e) {
                    $this->onHandleException($e);
                    if ($this->markConsumeFailure()) {
                        // 重试耗尽：记录后拒绝并不重新入队，交给 DLX 或丢弃，避免队列被毒消息卡死
                        $session->logDropped($pending, $e, $this->consumeFailedAttempts());
                        try {
                            $session->nack(false);
                        } catch (\Throwable $nackError) {
                            $this->onHandleException($nackError);
                        }
                        $pending = null;
                        $this->resetConsumeFailures();
                        continue;
                    }
                    $this->waitConsumeRetry(fn (): bool => !$this->running);
                    continue;
                }
                $this->resetConsumeFailures();
                try {
                    $session->ack();
                    $pending = null;
                } catch (\Throwable $e) {
                    $pending = null;
                    $this->resetConsumeFailures();
                    $this->onHandleException($e);
                    \Swoole\Coroutine::sleep(0.5);
                }
            }
        } finally {
            $session->close();
        }
    }
}
