<?php

declare(strict_types=1);

namespace Swoolefy\Support\Kafka;

use Swoolefy\Support\Kafka\Dto\ReceivedMessageDto;
use Swoolefy\Support\ConsumeRetryLimit;
use Swoolefy\Worker\AbstractWorkerProcess;

/**
 * Daemon / Cron 常驻进程里的 Kafka 消费。循环和退出沿用 AbstractWorkerProcess。
 * 同一个消费组 + Topic 的 worker_num 保持 1。
 * offset 何时提交由 $commitMode 决定，见 KafkaCommitMode。默认 onMessage() 成功后同步提交，
 * 抛异常时不提交，同一条会再交给 onMessage()。
 * 同一条连续失败达到 $maxRetryAttempts（默认 5，重试之间指数退避）后记录并提交 offset 丢弃，
 * 避免毒消息卡住整个分区；设为 0 或负数保持无限重试。退避参数见 \Swoolefy\Support\ConsumeRetryLimit。
 */
abstract class KafkaConsumerWorkerProcessHandler extends AbstractWorkerProcess
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
     * @var KafkaConsumeSession
     */
    private KafkaConsumeSession $session;

    /**
     * @var ReceivedMessageDto
     */
    private ?ReceivedMessageDto $pending = null;

    /**
     * BEFORE_HANDLE 模式下 pending 的 offset 是否已提交。
     */
    private bool $committed = false;

    /**
     * @param ReceivedMessageDto $message
     */
    abstract public function onMessage(ReceivedMessageDto $message): void;

    /**
     * 初始化
     */
    protected function onInit()
    {
        parent::onInit();
        $this->session = new KafkaConsumeSession($this->topic, $this->consumerGroup, $this->brokers, $this->commitMode);
    }

    /**
     * loopHandle循环处理
     */
    protected function loopHandle()
    {
        if ($this->waitToExit) {
            return;
        }
        if ($this->pending === null) {
            try {
                $this->pending = $this->session->poll();
            } catch (\Throwable $exception) {
                $this->onHandleException($exception);
                \Swoole\Coroutine::sleep(0.5);
                return;
            }
            if ($this->pending === null) {
                \Swoole\Coroutine::sleep(0.2);
                return;
            }
            $this->committed = false;
            $this->resetConsumeFailures();
        }
        if ($this->waitToExit) {
            return;
        }
        $onCommitError = function (\Throwable $exception): void {
            $this->onHandleException($exception);
        };
        $atMostOnce = $this->commitMode === KafkaCommitMode::BEFORE_HANDLE;
        // 至多一次：提交成功之前不能交给业务，否则提交失败后重投会重复处理
        if ($atMostOnce && !$this->committed) {
            if (!$this->session->commitBeforeHandle($onCommitError)) {
                \Swoole\Coroutine::sleep(0.5);
                return;
            }
            $this->committed = true;
        }
        try {
            $this->onMessage($this->pending);
        } catch (\Throwable $exception) {
            $this->onHandleException($exception);
            if ($atMostOnce) {
                // offset 已提交，这条不会再投递
                $this->session->logDropped($this->pending, $exception);
                $this->pending = null;
                return;
            }
            if ($this->markConsumeFailure()) {
                // 重试耗尽：记录后提交这一条的 offset，避免毒消息永久卡住分区
                $this->session->logRetryExhausted($this->pending, $exception, $this->consumeFailedAttempts());
                $this->session->commitAfterHandled($onCommitError);
                $this->pending = null;
                $this->resetConsumeFailures();
                return;
            }
            $this->waitConsumeRetry(fn (): bool => $this->waitToExit);
            return;
        }
        // 业务已成功：之后无论 commit 成败都不再重跑 onMessage()
        $this->pending = null;
        $this->resetConsumeFailures();
        $this->session->commitAfterHandled($onCommitError);
    }
}
