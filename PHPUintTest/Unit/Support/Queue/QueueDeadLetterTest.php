<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Support\Queue;

use PHPUintTest\TestCase;
use Swoolefy\Support\Queue\Dto\ReceivedMessageDto;
use Swoolefy\Support\Queue\QueueDeadLetter;

/**
 * 重试耗尽 / 无法重试的消息写入 {队列键}:dead_letter，按 $maxLength 截断，并刷新过期时间。
 */
final class QueueDeadLetterTest extends TestCase
{
    public function testPushWritesRecordTrimsAndExpires(): void
    {
        $redis = $this->fakeRedis();

        for ($i = 1; $i <= 3; $i++) {
            $message = new ReceivedMessageDto('{"id":' . $i . '}', 'queue', ['id' => $i, '__retry_num' => 5]);
            QueueDeadLetter::push($redis, 'queue:order:list', $message, QueueDeadLetter::REASON_RETRY_EXHAUSTED, new \RuntimeException('boom'), 2, 60);
        }

        $key = QueueDeadLetter::key('queue:order:list');
        $this->assertSame('queue:order:list:dead_letter', $key);
        $this->assertCount(2, $redis->lists[$key]);
        $this->assertSame(60, $redis->ttl[$key]);
        $latest = json_decode($redis->lists[$key][0], true);
        $this->assertSame('queue', $latest['queue']);
        $this->assertSame('retry_exhausted', $latest['reason']);
        $this->assertSame('{"id":3}', $latest['payload']);
        $this->assertSame(\RuntimeException::class, $latest['error_class']);
        $this->assertSame('boom', $latest['error_message']);
    }

    public function testNonPositiveLimitsFallBackToDefaults(): void
    {
        $redis = $this->fakeRedis();
        $message = new ReceivedMessageDto('{"id":1}', 'queue', ['id' => 1]);

        QueueDeadLetter::push($redis, 'q', $message, QueueDeadLetter::REASON_NOT_RETRYABLE, null, 0, 0);

        $key = QueueDeadLetter::key('q');
        $this->assertSame([0, QueueDeadLetter::DEFAULT_MAX_LENGTH - 1], $redis->trims[$key]);
        $this->assertSame(QueueDeadLetter::DEFAULT_TTL_SECONDS, $redis->ttl[$key]);
        $this->assertSame(5000, QueueDeadLetter::maxLength(-1));
        $this->assertSame(7 * 86400, QueueDeadLetter::ttlSeconds(-1));
    }

    private function fakeRedis(): object
    {
        return new class {
            /** @var array<string, list<string>> */
            public array $lists = [];

            /** @var array<string, array{int, int}> */
            public array $trims = [];

            /** @var array<string, int> */
            public array $ttl = [];

            public function lPush(string $key, string $value): int
            {
                $this->lists[$key] ??= [];
                array_unshift($this->lists[$key], $value);

                return count($this->lists[$key]);
            }

            public function lTrim(string $key, int $start, int $stop): bool
            {
                $this->trims[$key] = [$start, $stop];
                $this->lists[$key] = array_slice($this->lists[$key] ?? [], $start, $stop - $start + 1);

                return true;
            }

            public function expire(string $key, int $seconds): bool
            {
                $this->ttl[$key] = $seconds;

                return true;
            }
        };
    }
}
