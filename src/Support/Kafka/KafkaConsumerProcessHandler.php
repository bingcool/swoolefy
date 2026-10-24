<?php

declare(strict_types=1);

namespace Swoolefy\Support\Kafka;

use Swoolefy\Core\Process\AbstractProcess;
use Swoolefy\Support\ConsumeRetryLimit;
use Swoolefy\Support\Kafka\Dto\ReceivedMessageDto;

/**
 * 协议服务里的 Kafka 消费进程。业务实现 onMessage()，并写上 $topic、$consumerGroup。
 * offset 何时提交由 $commitMode 决定，见 KafkaCommitMode。默认 onMessage() 成功后同步提交，
 * 抛异常时不提交，同一条会再交给 onMessage()。
 * 同一条连续失败达到 $maxRetryAttempts（默认 5，重试之间指数退避）后记录并提交 offset 丢弃，
 * 避免毒消息卡住整个分区；设为 0 或负数保持无限重试。退避参数见 \Swoolefy\Support\ConsumeRetryLimit。
 */
abstract class KafkaConsumerProcessHandler extends AbstractProcess
{
    use ConsumeRetryLimit;

    /**
     * @var string
     */
    protected string $topic = '';

    /**
     * @var string
     */
    protected string $consumerGroup = '';

    /**
     * 默认读取dc.php的配置。一般无需设置
     * @var string
     */
    protected string $brokers = '';

    /**
     * offset 提交方式，KafkaCommitMode::*：
     * - AFTER_HANDLED（默认）：成功后同步提交，至少一次
     * - AFTER_HANDLED_ASYNC：成功后异步提交，至少一次，吞吐高、崩溃时重复更多
     * - BEFORE_HANDLE：处理前同步提交，至多一次，onMessage() 失败不重试只记日志
     * @var string
     */
    protected string $commitMode = KafkaCommitMode::AFTER_HANDLED;

    /**
     * @var bool
     */
    private bool $running = true;

    /**
     * @param ReceivedMessageDto $message
     * @return void
     */
    abstract public function onMessage(ReceivedMessageDto $message): void;

    /**
     * @return void
     */
    public function gracefulShutdownDrain(): void
    {
        $this->running = false;
    }

    /**
     * @return void
     */
    public function run()
    {
        $session = new KafkaConsumeSession($this->topic, $this->consumerGroup, $this->brokers, $this->commitMode);
        $onCommitError = function (\Throwable $exception): void {
            $this->onHandleException($exception);
        };
        $atMostOnce = $this->commitMode === KafkaCommitMode::BEFORE_HANDLE;
        $pending = null;
        $committed = false;
        while ($this->running) {
            if ($pending === null) {
                try {
                    $pending = $session->poll();
                } catch (\Throwable $exception) {
                    $this->onHandleException($exception);
                    \Swoole\Coroutine::sleep(0.5);
                    continue;
                }
                if ($pending === null) {
                    \Swoole\Coroutine::sleep(0.2);
                    continue;
                }
                $committed = false;
                $this->resetConsumeFailures();
            }
            if (!$this->running) {
                break;
            }
            // 至多一次：提交成功之前不能交给业务，否则提交失败后重投会重复处理
            if ($atMostOnce && !$committed) {
                if (!$session->commitBeforeHandle($onCommitError)) {
                    \Swoole\Coroutine::sleep(0.5);
                    continue;
                }
                $committed = true;
            }
            try {
                $this->onMessage($pending);
            } catch (\Throwable $exception) {
                $this->onHandleException($exception);
                if ($atMostOnce) {
                    // offset 已提交，这条不会再投递
                    $session->logDropped($pending, $exception);
                    $pending = null;
                    continue;
                }
                if ($this->markConsumeFailure()) {
                    // 重试耗尽：记录后提交这一条的 offset，避免毒消息永久卡住分区
                    $session->logRetryExhausted($pending, $exception, $this->consumeFailedAttempts());
                    $session->commitAfterHandled($onCommitError);
                    $pending = null;
                    $this->resetConsumeFailures();
                    continue;
                }
                $this->waitConsumeRetry(fn (): bool => !$this->running);
                continue;
            }
            // 业务已成功：之后无论 commit 成败都不再重跑 onMessage()
            $pending = null;
            $this->resetConsumeFailures();
            $session->commitAfterHandled($onCommitError);
        }
    }
}
