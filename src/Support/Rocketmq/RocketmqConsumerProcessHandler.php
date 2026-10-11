<?php

declare(strict_types=1);

namespace Swoolefy\Support\Rocketmq;

use Swoolefy\Core\Process\AbstractProcess;
use Swoolefy\Core\Sidecar\SidecarException;
use Swoolefy\Support\Rocketmq\Dto\ReceivedMessageDto;

/**
 * Swoole 自定义进程里的消费。一个进程拉一个消费组 + Topic。
 * 业务进程继承后实现 onMessage()，并写上 $topic、$consumerGroup。
 * onMessage() 抛异常时不确认，不可见时间结束后由 RocketMQ 再投。
 */
abstract class RocketmqConsumerProcessHandler extends AbstractProcess
{
    protected string $topic = '';

    protected string $consumerGroup = '';

    protected string $consumerTag = '*';

    protected int $maxNum = 8;

    protected int $invisibleMs = 20000;

    private bool $running = true;

    abstract public function onMessage(ReceivedMessageDto $message): void;

    public function gracefulShutdownDrain(): void
    {
        $this->running = false;
    }

    public function run()
    {
        $session = $this->openSession();
        while ($this->running) {
            try {
                $messages = $session->receive();
            } catch (\Throwable $exception) {
                $this->onHandleException($exception);
                \Swoole\Coroutine::sleep(0.5);
                continue;
            }
            if ($messages === []) {
                \Swoole\Coroutine::sleep(0.2);
                continue;
            }
            foreach ($messages as $message) {
                if (!$this->running) {
                    break;
                }
                try {
                    $this->onMessage($message);
                } catch (\Throwable $exception) {
                    $this->onHandleException($exception);
                    continue;
                }
                $session->ack($message);
            }
        }
    }

    private function openSession(): RocketmqConsumeSession
    {
        if ($this->topic === '' || $this->consumerGroup === '') {
            throw new SidecarException('rocketmq consumer topic and group are required');
        }

        return new RocketmqConsumeSession(
            $this->topic,
            $this->consumerGroup,
            $this->consumerTag,
            $this->maxNum,
            $this->invisibleMs,
        );
    }
}
