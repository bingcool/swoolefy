<?php
/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | @see https://github.com/bingcool/swoolefy
 * +----------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Swoolefy\Support\Job;

/**
 * Redis List dead-letter helper（零建表）。
 *
 * Key：{prefix}{queue}，默认 job:dead:default
 */
final class RedisDeadLetter
{
    /**
     * @param object $redis phpredis / predis 兼容对象（需 lPush / rPop）
     */
    public function __construct(
        private readonly object $redis,
        private readonly string $keyPrefix = 'job:dead:',
    ) {
    }

    public static function fromConfig(object $redis, ?JobConfig $config = null): self
    {
        $config ??= JobConfig::load();

        return new self($redis, $config->deadLetterRedisKeyPrefix());
    }

    public function key(string $queue = 'default'): string
    {
        return $this->keyPrefix . $queue;
    }

    public function push(JobEnvelope $job, string $error, string $queue = 'default'): void
    {
        // LPUSH：新死信在左端；配合 RPOP 形成 FIFO 重放
        $payload = json_encode([
            'job' => $job->toArray(),
            'error' => $error,
            'at' => time(),
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->redis->lPush($this->key($queue), $payload);
    }

    /**
     * 弹出一条死信（RPOP，FIFO，便于重放）。
     *
     * @return array{job: array<string, mixed>, error: string, at: int}|null
     */
    public function pop(string $queue = 'default'): ?array
    {
        $raw = $this->redis->rPop($this->key($queue));
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        // 结构损坏的条目丢弃（已从 List 弹出），避免卡死重放
        if (!is_array($decoded) || !isset($decoded['job']) || !is_array($decoded['job'])) {
            return null;
        }

        return [
            'job' => $decoded['job'],
            'error' => is_string($decoded['error'] ?? null) ? $decoded['error'] : '',
            'at' => (int) ($decoded['at'] ?? 0),
        ];
    }

    /**
     * 将最多 $limit 条死信重放到 $publish（attempt 重置为 1）。
     *
     * @param callable(array<string, mixed>): void $publish
     *
     * @return int 实际重放条数
     */
    public function replay(callable $publish, string $queue = 'default', int $limit = 1): int
    {
        $count = 0;
        $limit = max(1, $limit);
        while ($count < $limit) {
            $entry = $this->pop($queue);
            if ($entry === null) {
                break;
            }
            // 重放前将 attempt 重置为 1，重新走完整重试预算
            $job = JobEnvelope::fromArray($entry['job'])->withAttempt(1);
            $publish($job->toArray());
            ++$count;
        }

        return $count;
    }

    public function length(string $queue = 'default'): int
    {
        $len = $this->redis->lLen($this->key($queue));

        return is_int($len) ? $len : (int) $len;
    }
}
