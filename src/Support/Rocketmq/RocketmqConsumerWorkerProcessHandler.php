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
 */
abstract class RocketmqConsumerWorkerProcessHandler extends AbstractWorkerProcess
{
    protected string $topic = '';

    protected string $consumerGroup = '';

    protected string $consumerTag = '*';

    protected int $maxNum = 8;

    protected int $invisibleMs = 20000;

    private RocketmqConsumeSession $session;

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
        try {
            $messages = $this->session->receive();
        } catch (\Throwable) {
            \Swoole\Coroutine::sleep(1);
            return;
        }
        if ($messages === []) {
            \Swoole\Coroutine::sleep(0.5);
            return;
        }
        foreach ($messages as $message) {
            if ($this->waitToExit) {
                return;
            }
            try {
                $this->onMessage($message);
            } catch (\Throwable) {
                continue;
            }
            $this->session->ack($message);
        }
    }
}
