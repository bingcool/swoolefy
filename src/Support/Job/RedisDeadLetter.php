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
     * @param object $redis phpredis / predis 兼容对象（需 lPush / rPush / rPop / rPopLPush / lRem / lLen）
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
     * 重放中的暂存 List。RPOPLPUSH 把死信移到这里，publish 成功后才删除。
     */
    public function replayingKey(string $queue = 'default'): string
    {
        return $this->key($queue) . ':replaying';
    }

    /**
     * 结构损坏、无法重放的条目移到这里，不丢弃，便于人工排查。
     */
    public function corruptKey(string $queue = 'default'): string
    {
        return $this->key($queue) . ':corrupt';
    }

    /**
     * 将最多 $limit 条死信重放到 $publish（attempt 重置为 1）。
     *
     * 每条先 RPOPLPUSH 到 {key}:replaying，$publish 成功后再从暂存 List 删除。
     * $publish 抛异常时把这条放回死信 List 的出队端并抛出，死信不会丢。
     * 进程在 publish 中途退出时，这条留在暂存 List，下次 replay 开始时移回死信 List，可能重放两次，消费端需按 jobId 幂等。
     * 同一个 queue 不要并发运行多个 replay。
     *
     * @param callable(array<string, mixed>): void $publish
     *
     * @return int 实际重放条数
     */
    public function replay(callable $publish, string $queue = 'default', int $limit = 1): int
    {
        $source = $this->key($queue);
        $staging = $this->replayingKey($queue);
        $this->recoverStaging($source, $staging);

        $count = 0;
        $limit = max(1, $limit);
        while ($count < $limit) {
            $raw = $this->redis->rPopLPush($source, $staging);
            if (!is_string($raw) || $raw === '') {
                break;
            }

            $decoded = json_decode($raw, true);
            if (!is_array($decoded) || !isset($decoded['job']) || !is_array($decoded['job'])) {
                $this->redis->lPush($this->corruptKey($queue), $raw);
                $this->removeOne($staging, $raw);
                continue;
            }

            // 重放前将 attempt 重置为 1，重新走完整重试预算
            $job = JobEnvelope::fromArray($decoded['job'])->withAttempt(1);
            try {
                $publish($job->toArray());
            } catch (\Throwable $throwable) {
                $this->redis->rPush($source, $raw);
                $this->removeOne($staging, $raw);
                throw $throwable;
            }
            $this->removeOne($staging, $raw);
            ++$count;
        }

        return $count;
    }

    /**
     * 上一次 replay 中途退出留下的条目原子地移回死信 List（放在入队端，排在已有死信之后）。
     */
    private function recoverStaging(string $source, string $staging): void
    {
        while (true) {
            $raw = $this->redis->rPopLPush($staging, $source);
            if (!is_string($raw) || $raw === '') {
                return;
            }
        }
    }

    /**
     * phpredis 是 lRem(key, value, count)，Predis 是 lrem(key, count, value)。
     */
    private function removeOne(string $key, string $value): void
    {
        if ($this->redis instanceof \Swoolefy\Library\Redis\Predis || $this->redis instanceof \Predis\ClientInterface) {
            $this->redis->lRem($key, 1, $value);
            return;
        }
        $this->redis->lRem($key, $value, 1);
    }

    public function length(string $queue = 'default'): int
    {
        $len = $this->redis->lLen($this->key($queue));

        return is_int($len) ? $len : (int) $len;
    }
}
