<?php

declare(strict_types=1);

namespace Swoolefy\Support\Amqp;

use Swoolefy\Support\Amqp\Dto\ReceivedMessageDto;
use Swoolefy\Support\ConsumeRetryLimit;
use Swoolefy\Worker\AbstractWorkerProcess;

/**
 * Daemon / Cron 常驻进程里的 AMQP 消费。循环和退出沿用 AbstractWorkerProcess。
 * 同一队列的多个进程会分摊消息，每个进程单独建连。
 * onMessage() 抛异常时本地重试同一条，重试之间指数退避；达到 $maxRetryAttempts（默认 5）后记日志并
 * nack(requeue=false)，由队列的 x-dead-letter-exchange 转投死信或丢弃，避免毒消息卡住队列。
 * 设为 0 或负数保持无限重试。退避参数见 \Swoolefy\Support\ConsumeRetryLimit。
 *
 * bindingKey 是队列绑到交换机的规则。routingKey 只在 bindingKey 为空时顶替它，消费端不用它发消息。
 * 含义与 AmqpConsumerProcessHandler 相同。
 */
abstract class AmqpConsumerWorkerProcessHandler extends AbstractWorkerProcess
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
            $this->resetConsumeFailures();
        }
        if ($this->waitToExit) {
            return;
        }
        try {
            $this->onMessage($this->pending);
        } catch (\Throwable $e) {
            $this->onHandleException($e);
            if ($this->markConsumeFailure()) {
                // 重试耗尽：记录后拒绝并不重新入队，交给 DLX 或丢弃，避免队列被毒消息卡死
                $this->session->logDropped($this->pending, $e, $this->consumeFailedAttempts());
                try {
                    $this->session->nack(false);
                } catch (\Throwable $nackError) {
                    $this->onHandleException($nackError);
                }
                $this->pending = null;
                $this->resetConsumeFailures();

                return;
            }
            $this->waitConsumeRetry(fn (): bool => $this->waitToExit);

            return;
        }
        $this->resetConsumeFailures();
        try {
            $this->session->ack();
            $this->pending = null;
        } catch (\Throwable $e) {
            $this->pending = null;
            $this->resetConsumeFailures();
            $this->onHandleException($e);
            \Swoole\Coroutine::sleep(0.5);
        }
    }
}
