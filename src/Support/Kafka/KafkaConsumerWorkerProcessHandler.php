<?php

declare(strict_types=1);

namespace Swoolefy\Support\Kafka;

use Swoolefy\Support\Kafka\Dto\ReceivedMessageDto;
use Swoolefy\Worker\AbstractWorkerProcess;

/**
 * Daemon / Cron 常驻进程里的 Kafka 消费。循环和退出沿用 AbstractWorkerProcess。
 * 同一个消费组 + Topic 的 worker_num 保持 1。
 * onMessage() 抛异常时不提交 offset，同一条会再交给 onMessage()。
 */
abstract class KafkaConsumerWorkerProcessHandler extends AbstractWorkerProcess
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
     * @var KafkaConsumeSession
     */
    private KafkaConsumeSession $session;

    /**
     * @var ReceivedMessageDto
     */
    private ?ReceivedMessageDto $pending = null;

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
        $this->session = new KafkaConsumeSession($this->topic, $this->consumerGroup, $this->brokers);
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
            } catch (\Throwable) {
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
            $this->session->commit();
            $this->pending = null;
        } catch (\Throwable) {
            \Swoole\Coroutine::sleep(0.5);
        }
    }
}
