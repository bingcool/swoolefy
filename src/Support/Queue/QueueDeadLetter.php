<?php

declare(strict_types=1);

namespace Swoolefy\Support\Queue;

use Swoolefy\Support\Queue\Dto\ReceivedMessageDto;

/**
 * List / 延迟队列的死信列表。重试次数用完、或消息体不是数组无法 retry 时写到这里，不再静默丢弃。
 *
 * 键：{队列键}:dead_letter，是一个 Redis List，LPUSH 进去，最新的在左边。
 * 每个元素是 JSON：
 * - queue：组件名（$queue_component）
 * - reason：retry_exhausted | not_retryable
 * - payload：原消息 JSON，含 __id / __retry_num / __timestamp
 * - error_class / error_message：最后一次 onMessage() 抛出的异常
 * - failed_at：写入死信的 unix 时间
 *
 * 容量始终有上限：LTRIM 只保留最新的 $maxLength 条，<= 0 时按 DEFAULT_MAX_LENGTH 处理。
 * 每次写入后 EXPIRE 刷新为 $ttlSeconds，<= 0 时按 DEFAULT_TTL_SECONDS 处理。
 * 连续 $ttlSeconds 没有新死信时整个 key 被 Redis 删除。
 */
final class QueueDeadLetter
{
    public const REASON_RETRY_EXHAUSTED = 'retry_exhausted';

    public const REASON_NOT_RETRYABLE = 'not_retryable';

    public const DEFAULT_MAX_LENGTH = 5000;

    public const DEFAULT_TTL_SECONDS = 7 * 86400;

    public static function key(string $queueKey): string
    {
        return $queueKey . ':dead_letter';
    }

    /**
     * @param object $redis Swoolefy\Library\Redis\Redis 或 Predis，lPush / lTrim / expire 由它们转给真实客户端
     */
    public static function push(
        object $redis,
        string $queueKey,
        ReceivedMessageDto $message,
        string $reason,
        ?\Throwable $error,
        int $maxLength = self::DEFAULT_MAX_LENGTH,
        int $ttlSeconds = self::DEFAULT_TTL_SECONDS
    ): void {
        $record = json_encode([
            'queue' => $message->getQueue(),
            'reason' => $reason,
            'payload' => $message->getPayload(),
            'error_class' => $error !== null ? $error::class : '',
            'error_message' => $error !== null ? $error->getMessage() : '',
            'failed_at' => time(),
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);

        $key = self::key($queueKey);
        $redis->lPush($key, $record);
        $redis->lTrim($key, 0, self::maxLength($maxLength) - 1);
        $redis->expire($key, self::ttlSeconds($ttlSeconds));
    }

    public static function maxLength(int $maxLength): int
    {
        return $maxLength > 0 ? $maxLength : self::DEFAULT_MAX_LENGTH;
    }

    public static function ttlSeconds(int $ttlSeconds): int
    {
        return $ttlSeconds > 0 ? $ttlSeconds : self::DEFAULT_TTL_SECONDS;
    }
}
