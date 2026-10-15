<?php

declare(strict_types=1);

namespace Swoolefy\Support\Rocketmq;

/**
 * 在 onMessage() 里抛出，表示这条消息暂时处理不了，过 $retryAfterMs 毫秒后再投。
 *
 * 基类捕获后不 ack，把这条消息的不可见时间改成 $retryAfterMs，不再等满 $invisibleMs。
 * 不会传给 onHandleException()。改不可见时间失败只记日志，消息仍按原不可见时间重投。
 * 实际重投会比 $retryAfterMs 晚一些：消息重新可见后，要等消费端下一次长轮询拉取（大约 5 秒）。
 *
 * ```php
 * if (!$this->stockReady($orderNo)) {
 *     throw new RocketmqRetryLaterException(30000);
 * }
 * ```
 */
class RocketmqRetryLaterException extends \RuntimeException
{
    /**
     * @param int $retryAfterMs 多久后重投，按 10000–300000 毫秒夹取（proxy 不接受小于 10 秒）
     */
    public function __construct(
        public readonly int $retryAfterMs,
        string $message = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message !== '' ? $message : 'rocketmq retry after ' . $retryAfterMs . 'ms', 0, $previous);
    }
}
