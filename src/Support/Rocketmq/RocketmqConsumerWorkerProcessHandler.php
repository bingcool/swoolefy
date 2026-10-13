<?php

declare(strict_types=1);

namespace Swoolefy\Support\Rocketmq;

use Swoolefy\Core\Sidecar\SidecarException;
use Swoolefy\Support\Rocketmq\Dto\ReceivedMessageDto;
use Swoolefy\Worker\AbstractWorkerProcess;

/**
 * Daemon / Cron 常驻进程里的消费。循环、退出和定时重启沿用 AbstractWorkerProcess。
 * 业务进程继承后实现 onMessage()，并写上 $topic、$consumerGroup。
 * 同一个消费组 + Topic 的 worker_num 保持 1。
 * onMessage() 抛异常时不确认，不可见时间结束后由 RocketMQ 再投。
 *
 * 一次最多拉 $maxNum 条，按顺序处理。每条开始前检查本批的不可见时间：
 * 距离到期不足 $ackSafetyMarginMs 时，本批剩下的消息不再处理、也不确认，等 RocketMQ 再投。
 * 否则 RocketMQ 已经把它重新投给别的消费者，这里再处理就会重复。
 * 单条处理慢时调大 $invisibleMs 或调小 $maxNum，保证 $maxNum × 单条耗时 < $invisibleMs - $ackSafetyMarginMs。
 */
abstract class RocketmqConsumerWorkerProcessHandler extends AbstractWorkerProcess
{
    protected string $topic = '';

    protected string $consumerGroup = '';

    protected string $consumerTag = '*';

    protected int $maxNum = 8;

    protected int $invisibleMs = 30000;

    /**
     * 本批不可见时间到期前预留的毫秒数，留给最后一条的 onMessage() 和 ack。
     */
    protected int $ackSafetyMarginMs = 2000;

    /**
     * 进程启动后第一次 receive 前等待的秒数，让 go-sidecar 先开始监听，避免启动时报 connect failed。
     * 默认 2 秒，最大 5 秒（超过按 5 秒），0 表示不等。在第一次 loopHandle() 里等，不阻塞 onInit()。
     */
    protected float $sidecarStartupWaitSeconds = 2.0;

    private RocketmqConsumeSession $session;

    private bool $sidecarStartupWaited = false;

    abstract public function onMessage(ReceivedMessageDto $message): void;

    protected function onInit()
    {
        parent::onInit();
        if ($this->topic === '' || $this->consumerGroup === '') {
            throw new SidecarException('rocketmq consumer topic and group are required');
        }
        $this->session = new RocketmqConsumeSession(
            $this->topic,
            $this->consumerGroup,
            $this->consumerTag,
            $this->maxNum,
            $this->invisibleMs,
        );
    }

    protected function loopHandle()
    {
        if ($this->waitToExit) {
            return;
        }
        if (!$this->sidecarStartupWaited) {
            $this->sidecarStartupWaited = true;
            $wait = RocketmqConsumeSession::sidecarStartupWaitSeconds($this->sidecarStartupWaitSeconds);
            if ($wait > 0) {
                \Swoole\Coroutine::sleep($wait);
                return;
            }
        }
        try {
            $messages = $this->session->receive();
        } catch (\Throwable $exception) {
            $this->onHandleException($exception);
            \Swoole\Coroutine::sleep(0.5);
            return;
        }
        if ($messages === []) {
            \Swoole\Coroutine::sleep(0.2);
            return;
        }
        $deadlineNs = $this->session->handleDeadlineNs(hrtime(true), $this->ackSafetyMarginMs);
        foreach ($messages as $message) {
            if ($this->waitToExit) {
                return;
            }
            if (hrtime(true) >= $deadlineNs) {
                return;
            }
            try {
                $this->onMessage($message);
            } catch (\Throwable $exception) {
                $this->onHandleException($exception);
                continue;
            }
            $this->session->ack($message);
        }
    }
}
