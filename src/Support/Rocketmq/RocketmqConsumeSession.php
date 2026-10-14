<?php

declare(strict_types=1);

namespace Swoolefy\Support\Rocketmq;

use Swoolefy\Core\Sidecar\Dto\SidecarResponseDto;
use Swoolefy\Core\Sidecar\OutcomeUnknownException;
use Swoolefy\Core\Sidecar\SidecarClient;
use Swoolefy\Core\Sidecar\SidecarException;
use Swoolefy\Support\Rocketmq\Dto\AckMessageRequestDto;
use Swoolefy\Support\Rocketmq\Dto\ChangeInvisibleRequestDto;
use Swoolefy\Support\Rocketmq\Dto\ChangeInvisibleResponseDto;
use Swoolefy\Support\Rocketmq\Dto\ReceiveMessageRequestDto;
use Swoolefy\Support\Rocketmq\Dto\ReceiveMessageResponseDto;
use Swoolefy\Support\Rocketmq\Dto\ReceivedMessageDto;
use Swoolefy\Support\SupportLog;

/**
 * 一次消费订阅上的拉取、确认和改不可见时间。调用方负责循环。
 */
final class RocketmqConsumeSession
{
    /**
     * proxy 允许的最小不可见时间（毫秒），小于它会返回 ILLEGAL_INVISIBLE_TIME。
     */
    public const MIN_INVISIBLE_MS = 10000;

    /**
     * 不可见时间上限（毫秒），与 go-sidecar 一致。
     */
    public const MAX_INVISIBLE_MS = 300000;

    /**
     * 首次 receive 前等待 sidecar 监听的最长秒数。
     */
    public const MAX_SIDECAR_STARTUP_WAIT_SECONDS = 5.0;

    /**
     * 调 rocketmq sidecar 的 UDS 客户端。
     */
    private SidecarClient $client;

    /**
     * dispatch() 正在处理的消息，供 extendCurrent() 续租。不在 dispatch() 里时为 null。
     */
    private ?ReceivedMessageDto $current = null;

    /**
     * 发给 sidecar 的 Tag 表达式：* 或 paid || cancel。
     */
    private readonly string $consumerTag;

    /**
     * SQL92 属性过滤表达式（已去首尾空白），空串表示不用 SQL 过滤。
     */
    private readonly string $consumerSql;

    /**
     * 过滤条件是订阅配置：在这里定一次，之后每次 receive() 原样带给 sidecar。
     * sidecar 只在第一次见到这个消费组 + Topic 时订阅，sidecar 重启后下一次 receive 自动重新订阅。
     *
     * @param string $topic 要消费的 Topic
     * @param string $consumerGroup 消费组
     * @param list<string> $consumerTags 要订阅的 Tag，例如 ['paid', 'cancel']；空数组或含 * 表示全部
     * @param int $maxNum 一次最多拉取条数，发送时夹到 1–32
     * @param int $invisibleMs 不可见时间（毫秒），发送时夹到 10000–300000
     * @param string $consumerSql SQL92 属性过滤，空表示不用；非空时 $consumerTags 必须是全部
     * @throws \InvalidArgumentException Tag 不合法，或同时给了 $consumerSql 和具体 Tag
     */
    public function __construct(
        private readonly string $topic,
        private readonly string $consumerGroup,
        array $consumerTags,
        private readonly int $maxNum,
        private readonly int $invisibleMs,
        string $consumerSql = '',
    ) {
        $this->consumerTag = self::tagExpression($consumerTags);
        $this->consumerSql = trim($consumerSql);
        if ($this->consumerSql !== '' && $this->consumerTag !== '*') {
            throw new \InvalidArgumentException(
                "rocketmq consumerTag and consumerSql are exclusive; put the tag into SQL, e.g. TAGS IN ('paid')"
            );
        }
        $this->client = new SidecarClient();
    }

    /**
     * 把 Tag 数组转成 RocketMQ SDK 的 Tag 表达式：['paid', 'cancel'] → "paid || cancel"。
     * 空数组、全是空串、或包含 * 时为 "*"（订阅全部）。去掉首尾空白和重复项，保留首次出现的顺序。
     * Tag 里不能有 "||"：broker 按 "||" 切分表达式，带它的 Tag 会被拆成两个。
     *
     * @param array<mixed> $tags
     * @throws \InvalidArgumentException 元素不是字符串，或含 "||"
     */
    public static function tagExpression(array $tags): string
    {
        $out = [];
        foreach ($tags as $tag) {
            if (!is_string($tag)) {
                throw new \InvalidArgumentException('rocketmq consumerTag must be a list of strings');
            }
            $tag = trim($tag);
            if ($tag === '') {
                continue;
            }
            if ($tag === '*') {
                return '*';
            }
            if (str_contains($tag, '||')) {
                throw new \InvalidArgumentException('rocketmq consumerTag item must not contain "||": ' . $tag);
            }
            $out[$tag] = true;
        }

        return $out === [] ? '*' : implode(' || ', array_keys($out));
    }

    /**
     * 拉一批消息，阻塞到有消息或长轮询结束（最多约 25 秒）。没有消息时返回空数组。
     *
     * @return list<ReceivedMessageDto>
     * @throws \Throwable sidecar 不可用、同组 + Topic 已有 receive 在途（OVERLOADED）、过滤条件不合法等
     */
    public function receive(): array
    {
        $request = new ReceiveMessageRequestDto();
        $request->setTopic($this->topic);
        $request->setConsumerGroup($this->consumerGroup);
        $request->setConsumerTag($this->consumerTag);
        $request->setConsumerSql($this->consumerSql);
        $request->setMaxNum($this->bounded($this->maxNum, 1, 32));
        $request->setInvisibleMs($this->effectiveInvisibleMs());
        $response = $this->client->call('rocketmq', 'rocketmq.consumer.receive', $request, 25000);
        $this->assertReceiveOk($response);
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
     * 续租只延长当前那一条，不改变这里算出的本批截止时刻。
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
     * 处理一条消息：正常返回就 ack；抛 RocketmqRetryLaterException 就改不可见时间、不 ack；
     * 抛其他异常就交给 $onException、不 ack，等不可见时间结束后再投。
     *
     * @param callable(ReceivedMessageDto): void $handler 业务的 onMessage()
     * @param callable(\Throwable): void $onException 业务的 onHandleException()
     * @return bool 是否 ack 成功
     */
    public function dispatch(ReceivedMessageDto $message, callable $handler, callable $onException): bool
    {
        $this->current = $message;
        try {
            $handler($message);
        } catch (RocketmqRetryLaterException $retry) {
            $this->tryChangeInvisible($message, $retry->retryAfterMs, 'retry later');

            return false;
        } catch (\Throwable $exception) {
            $onException($exception);

            return false;
        } finally {
            $this->current = null;
        }

        return $this->ack($message);
    }

    /**
     * 给 dispatch() 正在处理的那条消息续租：从现在起再隐藏 $invisibleMs 毫秒。
     * 不在 onMessage() 里调用时什么也不做，返回 false。
     *
     * 服务端限制：RocketMQ proxy 在 apache/rocketmq#9583 修复之前（5.3.x 及更早），续租之后的 ack 虽然返回成功，
     * 消息仍可能在续租时间到期后重投。在这些版本上，长任务优先调大 $invisibleMs，续租只作兜底，业务必须幂等。
     *
     * @return bool 续租是否成功。失败已记日志；消息可能在原不可见时间到期后被重投，业务按 messageId 幂等
     */
    public function extendCurrent(int $invisibleMs): bool
    {
        if ($this->current === null) {
            return false;
        }

        return $this->tryChangeInvisible($this->current, $invisibleMs, 'extend');
    }

    /**
     * 把这条消息的不可见时间改成「从现在起 $invisibleMs 毫秒」，按 10000–300000 夹取。
     *
     * proxy 每次都会签发新回执、旧回执立即失效，所以成功后直接替换 $message 里的 receiptHandle，
     * 之后对同一个 DTO 调用 ack() 自然用的是新回执。
     *
     * @throws \Throwable sidecar 调用失败；回执已过期时 sidecarCode 为 INVALID_ARGUMENT
     */
    public function changeInvisible(ReceivedMessageDto $message, int $invisibleMs): void
    {
        $request = new ChangeInvisibleRequestDto();
        $request->setConsumerGroup($this->consumerGroup);
        $request->setTopic($message->getTopic());
        $request->setMessageId($message->getMessageId());
        $request->setReceiptHandle($message->getReceiptHandle());
        $request->setInvisibleMs($this->bounded($invisibleMs, self::MIN_INVISIBLE_MS, self::MAX_INVISIBLE_MS));
        $response = $this->client->call('rocketmq', 'rocketmq.consumer.changeInvisible', $request, 5000);
        $this->client->assertOk($response);
        $receipt = ChangeInvisibleResponseDto::fromArray($response->getPayload())->getReceiptHandle();
        if ($receipt !== '') {
            $message->setReceiptHandle($receipt);
        }
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
            $this->logFailure('rocketmq ack failed', $message, $exception);

            return false;
        }
    }

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

    /**
     * 不认识 consumerSql 的旧 sidecar 只回 INVALID_ARGUMENT / payload，把提示改成能直接定位的升级说明。
     */
    private function assertReceiveOk(SidecarResponseDto $response): void
    {
        try {
            $this->client->assertOk($response);
        } catch (SidecarException $exception) {
            if ($this->consumerSql !== ''
                && $exception->sidecarCode === SidecarResponseDto::CODE_INVALID_ARGUMENT
                && $exception->getMessage() === 'payload'
            ) {
                throw new SidecarException(
                    'rocketmq sidecar rejected payload; it may be too old to support consumerSql, upgrade go-sidecar',
                    0,
                    $exception,
                    $exception->sidecarCode,
                );
            }
            throw $exception;
        }
    }

    /**
     * changeInvisible() 的不抛异常版本。失败只记日志：消息仍按原不可见时间重投，不影响正确性。
     */
    private function tryChangeInvisible(ReceivedMessageDto $message, int $invisibleMs, string $reason): bool
    {
        try {
            $this->changeInvisible($message, $invisibleMs);

            return true;
        } catch (\Throwable $exception) {
            $this->logFailure('rocketmq change invisible failed (' . $reason . ')', $message, $exception);

            return false;
        }
    }

    /**
     * 记一条 ack / changeInvisible 失败日志。记日志本身出错也吞掉，不能让消费循环因为日志退出。
     */
    private function logFailure(string $title, ReceivedMessageDto $message, \Throwable $exception): void
    {
        try {
            SupportLog::error('rocketmq', $title, [
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
    }

    /**
     * 把 $value 夹到 [$min, $max]。
     */
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
