<?php

declare(strict_types=1);

namespace Swoolefy\Support\Rocketmq;

use Swoolefy\Core\Sidecar\OutcomeUnknownException;
use Swoolefy\Core\Sidecar\SidecarClient;
use Swoolefy\Support\Rocketmq\Dto\AckMessageRequestDto;
use Swoolefy\Support\Rocketmq\Dto\ReceiveMessageRequestDto;
use Swoolefy\Support\Rocketmq\Dto\ReceiveMessageResponseDto;
use Swoolefy\Support\Rocketmq\Dto\ReceivedMessageDto;
use Swoolefy\Support\SupportLog;

/**
 * 一次消费订阅上的拉取和确认。调用方负责循环和 onMessage。
 */
final class RocketmqConsumeSession
{
    public const MIN_INVISIBLE_MS = 10000;

    public const MAX_INVISIBLE_MS = 300000;

    public const MAX_SIDECAR_STARTUP_WAIT_SECONDS = 5.0;

    /**
     * @var SidecarClient
     */
    private SidecarClient $client;

    /**
     * @param string $topic
     * @param string $consumerGroup
     * @param string $consumerTag
     * @param int $maxNum
     * @param int $invisibleMs
     */
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
        $request->setInvisibleMs($this->effectiveInvisibleMs());
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

    /**
     * 本批消息的处理截止时刻（hrtime 纳秒）。超过后不可见时间到期，RocketMQ 会把消息再投给别的消费者。
     *
     * @param int $receivedAtNs receive() 返回时的 hrtime(true)
     * @param int $safetyMarginMs 留给 onMessage() + ack 的余量
     */
    public function handleDeadlineNs(int $receivedAtNs, int $safetyMarginMs): int
    {
        $budgetMs = max(0, $this->effectiveInvisibleMs() - max(0, $safetyMarginMs));

        return $receivedAtNs + $budgetMs * 1_000_000;
    }

    /**
     * 确认失败不抛出、不退出进程：不可见时间结束后由 RocketMQ 再投，业务需要按 messageId 幂等。
     * OutcomeUnknownException 表示请求已写出但没拿到结果，broker 可能已经确认，也可能会再投。
     *
     * @param ReceivedMessageDto $message
     * @return bool 是否确认成功
     */
    public function ack(ReceivedMessageDto $message): bool
    {
        try {
            $ack = new AckMessageRequestDto();
            $ack->setConsumerGroup($this->consumerGroup);
            $ack->setReceiptHandle($message->getReceiptHandle());
            $ack->setMessageId($message->getMessageId());
            $ack->setTopic($message->getTopic());
            $this->client->assertOk($this->client->call('rocketmq', 'rocketmq.consumer.ack', $ack, 5000));

            return true;
        } catch (\Throwable $exception) {
            try {
                SupportLog::error('rocketmq', 'rocketmq ack failed', [
                    'topic' => $message->getTopic(),
                    'consumerGroup' => $this->consumerGroup,
                    'messageId' => $message->getMessageId(),
                    'outcomeUnknown' => $exception instanceof OutcomeUnknownException,
                    'message' => $exception->getMessage(),
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                ]);
            } catch (\Throwable) {
            }

            return false;
        }
    }

    /**
     * @param int $value
     * @param int $min
     * @param int $max
     * @return int
     */
    /**
     * 实际发给 proxy 的不可见时间。proxy 默认拒绝小于 10 秒的值（ILLEGAL_INVISIBLE_TIME，minInvisibleTimeMillsForRecv），
     * 每次 receive 都会失败，所以这里抬到 10 秒；上限与 Go sidecar 一致为 5 分钟。截止时间也按这个值算。
     */
    public function effectiveInvisibleMs(): int
    {
        return $this->bounded($this->invisibleMs, self::MIN_INVISIBLE_MS, self::MAX_INVISIBLE_MS);
    }

    /**
     * 消费进程第一次 receive 前等待 go-sidecar 开始监听的秒数，限制在 0 到 5 秒之间。
     */
    public static function sidecarStartupWaitSeconds(float $seconds): float
    {
        return max(0.0, min(self::MAX_SIDECAR_STARTUP_WAIT_SECONDS, $seconds));
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
