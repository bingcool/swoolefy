<?php

declare(strict_types=1);

namespace Swoolefy\Support\Rocketmq;

use Swoolefy\Core\Sidecar\SidecarClient;
use Swoolefy\Support\Rocketmq\Dto\AckMessageRequestDto;
use Swoolefy\Support\Rocketmq\Dto\ReceiveMessageRequestDto;
use Swoolefy\Support\Rocketmq\Dto\ReceiveMessageResponseDto;
use Swoolefy\Support\Rocketmq\Dto\ReceivedMessageDto;

/**
 * 一次消费订阅上的拉取和确认。调用方负责循环和 onMessage。
 */
final class RocketmqConsumeSession
{
    private SidecarClient $client;

    public function __construct(
        private readonly string $topic,
        private readonly string $consumerGroup,
        private readonly string $consumerTag,
        private readonly int $maxNum,
        private readonly int $invisibleMs,
    ) {
        $this->client = new SidecarClient();
    }

    /**
     * @return list<ReceivedMessageDto>
     */
    public function receive(): array
    {
        $request = new ReceiveMessageRequestDto();
        $request->setTopic($this->topic);
        $request->setConsumerGroup($this->consumerGroup);
        $request->setConsumerTag($this->consumerTag === '' ? '*' : $this->consumerTag);
        $request->setMaxNum($this->bounded($this->maxNum, 1, 32));
        $request->setInvisibleMs($this->bounded($this->invisibleMs, 1, 300000));
        $response = $this->client->call('rocketmq', 'rocketmq.consumer.receive', $request, 25000);
        $this->client->assertOk($response);
        $messages = ReceiveMessageResponseDto::fromArray($response->getPayload())->getMessages();
        $out = [];
        foreach ($messages as $item) {
            if (!is_array($item)) {
                continue;
            }
            $out[] = ReceivedMessageDto::fromArray($item);
        }

        return $out;
    }

    public function ack(ReceivedMessageDto $message): void
    {
        try {
            $ack = new AckMessageRequestDto();
            $ack->setConsumerGroup($this->consumerGroup);
            $ack->setReceiptHandle($message->getReceiptHandle());
            $ack->setMessageId($message->getMessageId());
            $ack->setTopic($message->getTopic());
            $this->client->assertOk($this->client->call('rocketmq', 'rocketmq.consumer.ack', $ack, 3000));
        } catch (\Throwable) {
            // 确认失败不退出进程。不可见时间结束后由 RocketMQ 再投。
        }
    }

    private function bounded(int $value, int $min, int $max): int
    {
        if ($value < $min) {
            return $min;
        }
        if ($value > $max) {
            return $max;
        }

        return $value;
    }
}
