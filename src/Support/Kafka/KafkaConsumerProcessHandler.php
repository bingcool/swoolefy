<?php

declare(strict_types=1);

namespace Swoolefy\Support\Kafka;

use Swoolefy\Core\Process\AbstractProcess;
use Swoolefy\Support\Kafka\Dto\ReceivedMessageDto;

/**
 * 协议服务里的 Kafka 消费进程。业务实现 onMessage()，并写上 $topic、$consumerGroup。
 * onMessage() 抛异常时不提交 offset，同一条会再交给 onMessage()。
 */
abstract class KafkaConsumerProcessHandler extends AbstractProcess
{
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
        $session = new KafkaConsumeSession($this->topic, $this->consumerGroup, $this->brokers);
        $pending = null;
        while ($this->running) {
            if ($pending === null) {
                try {
                    $pending = $session->poll();
                } catch (\Throwable) {
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
                $session->commit();
                $pending = null;
            } catch (\Throwable) {
                \Swoole\Coroutine::sleep(0.5);
            }
        }
    }
}
