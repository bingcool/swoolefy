<?php

declare(strict_types=1);

namespace Test\Process\SubscribeProcess;

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoolefy\Core\Process\AbstractProcess;
use Swoolefy\Core\SystemEnv;
use Swoolefy\Library\PubSub\AbstractPubSub;
use Swoolefy\Library\PubSub\Exception\InvalidSubscriptionException;
use Swoolefy\Library\PubSub\Exception\PubSubException;
use Swoolefy\Library\PubSub\Exception\SubscriptionConflictException;
use Swoolefy\Library\PubSub\PredisPubSub;
use Swoolefy\Library\PubSub\RedisPubSub;
use Swoolefy\Library\Redis\Predis as PredisConnection;
use Swoolefy\Library\Redis\Redis as PhpRedisConnection;

/**
 * PubSub 端到端验证：一个场景一个进程，phpredis / predis 两套驱动全量覆盖。
 *
 * daemon 模式 stdout 不可靠，结果同时写入容器内 /tmp/pubsub-verify/verify.log 与 results.jsonl。
 * 订阅是阻塞循环，这里用「进程常驻」代替退出：自定义进程退出会被 Manager 反复拉起，导致场景重复执行。
 */
class PubSubVerify extends AbstractProcess
{
    private const LOG_DIR = '/tmp/pubsub-verify';

    /** 单个场景的看门狗时长（秒），覆盖 predis 空闲 3 秒用例 */
    private const SCENARIO_TIMEOUT = 20;

    /** @var list<string> 后台协程（发布端）的异常，场景结束后统一断言 */
    private array $asyncErrors = [];

    /**
     * 场景清单：除 invalid_inputs 外均按 phpredis / predis 各跑一遍，断言标准一致。
     *
     * @return list<string>
     */
    public static function scenarios(): array
    {
        return [
            // 内联订阅：回调参数、消息顺序、统计计数，以及同一实例结束后的二次订阅
            'phpredis_inline',
            'predis_inline',
            // 模式订阅：通配符匹配，回调签名为 (pattern, channel, payload)
            'phpredis_pattern',
            'predis_pattern',
            // 发布端：publish() 返回订阅端数量；无订阅者时返回 0
            'phpredis_publish_receivers',
            'predis_publish_receivers',
            // 协程派发：并发峰值不超过 max_concurrency，stop() 后由唤醒消息结束循环
            'phpredis_coroutine_backpressure',
            'predis_coroutine_backpressure',
            // 协程异常：回调异常交给 on_error 并计入 failed，主循环继续消费后续消息
            'phpredis_coroutine_on_error',
            'predis_coroutine_on_error',
            // 跨协程停止：stop() 只登记停止请求，下一条消息到达后才真正退订退出
            'phpredis_stop_cross',
            'predis_stop_cross',
            // 冲突保护：订阅态下 publish、重入 subscribe、跨协程 unsubscribe 均应抛异常
            'phpredis_conflict_guards',
            'predis_conflict_guards',
            // 空闲订阅：超过连接读超时时间仍能收消息，退出后恢复原有超时配置
            'phpredis_idle_timeout',
            'predis_idle_timeout',
            // 参数校验：非法频道/模式/选项及错误连接类型在建立订阅会话前 fail-fast
            'invalid_inputs',
        ];
    }

    public function run()
    {
        $scenario = (string) ($this->getArgs()['scenario'] ?? '');
        if (!in_array($scenario, self::scenarios(), true)) {
            self::record('unknown', 'FAIL', 0.0, '未知场景：' . $scenario);
            $this->hold();
        }

        // 自定义进程不经过 workerStartInit，hook 必须显式开启；
        // hook 只作用于开启之后创建的 socket，所以放在所有建连之前。
        \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

        $config = self::redisConfig();
        self::log(sprintf('START scenario=%s pid=%d redis=%s:%d', $scenario, getmypid(), $config['host'], $config['port']));

        $startedAt = microtime(true);
        $watchdog = \Swoole\Timer::after(self::SCENARIO_TIMEOUT * 1000, static function () use ($scenario): void {
            self::record($scenario, 'TIMEOUT', self::SCENARIO_TIMEOUT, '看门狗超时');
            \Swoole\Process::kill(getmypid(), SIGKILL);
        });

        try {
            $this->dispatchScenario($scenario);
            $this->assertSame([], $this->asyncErrors, '后台发布协程失败');
            self::record($scenario, 'PASS', microtime(true) - $startedAt, 'ok');
        } catch (\Throwable $e) {
            self::record($scenario, 'FAIL', microtime(true) - $startedAt, get_class($e) . ': ' . $e->getMessage());
        } finally {
            \Swoole\Timer::clear($watchdog);
        }

        $this->hold();
    }

    private function dispatchScenario(string $scenario): void
    {
        // 场景名前缀决定驱动（phpredis / predis），后缀对应 scenarios() 中注明的断言集
        match ($scenario) {
            'phpredis_inline' => $this->scenarioInline('phpredis'),
            'predis_inline' => $this->scenarioInline('predis'),
            'phpredis_pattern' => $this->scenarioPattern('phpredis'),
            'predis_pattern' => $this->scenarioPattern('predis'),
            'phpredis_publish_receivers' => $this->scenarioPublishReceivers('phpredis'),
            'predis_publish_receivers' => $this->scenarioPublishReceivers('predis'),
            'phpredis_coroutine_backpressure' => $this->scenarioCoroutineBackpressure('phpredis'),
            'predis_coroutine_backpressure' => $this->scenarioCoroutineBackpressure('predis'),
            'phpredis_coroutine_on_error' => $this->scenarioCoroutineOnError('phpredis'),
            'predis_coroutine_on_error' => $this->scenarioCoroutineOnError('predis'),
            'phpredis_stop_cross' => $this->scenarioStopCross('phpredis'),
            'predis_stop_cross' => $this->scenarioStopCross('predis'),
            'phpredis_conflict_guards' => $this->scenarioConflictGuards('phpredis'),
            'predis_conflict_guards' => $this->scenarioConflictGuards('predis'),
            'phpredis_idle_timeout' => $this->scenarioPhpRedisIdleTimeout(),
            'predis_idle_timeout' => $this->scenarioPredisIdleTimeout(),
            'invalid_inputs' => $this->scenarioInvalidInputs(),
            default => throw new \RuntimeException('未知场景：' . $scenario),
        };
    }

    /**
     * 回调只有 channel/payload；收到两条后返回 false 结束；同一实例可再次订阅。
     */
    private function scenarioInline(string $driver): void
    {
        $channelA = $this->channel($driver . '_inline_a');
        $channelB = $this->channel($driver . '_inline_b');
        $rows = [];
        $argCounts = [];

        $sub = $this->pubSub($driver);
        $this->publishLater($driver, [$channelA => ['m1', 'm2']], 0.3);

        $sub->subscribe([$channelA], function (string ...$args) use (&$rows, &$argCounts): ?bool {
            $argCounts[] = count($args);
            $rows[] = $args;

            return count($rows) >= 2 ? false : null;
        });

        $this->assertSame([2, 2], $argCounts, $driver . ' 回调只应收到 channel/payload 两个参数');
        $this->assertSame([[$channelA, 'm1'], [$channelA, 'm2']], $rows, $driver . ' 内联订阅消息不匹配');

        $stats = $sub->stats();
        $this->assertSame(2, $stats['received'], $driver . ' received 计数');
        $this->assertSame(2, $stats['dispatched'], $driver . ' dispatched 计数');
        $this->assertSame(2, $stats['completed'], $driver . ' completed 计数');
        $this->assertSame(0, $stats['failed'], $driver . ' failed 计数');
        $this->assertSame(0, $stats['in_flight'], $driver . ' in_flight 计数');
        $this->assertFalse($stats['running'], $driver . ' subscribe() 返回后 running 应为 false');
        $this->assertSame([], $stats['channels'], $driver . ' subscribe() 返回后渠道应清空');

        $second = [];
        $this->publishLater($driver, [$channelB => ['m3']], 0.2);
        $sub->subscribe([$channelB], function (string $channel, string $payload) use (&$second): bool {
            $second[] = [$channel, $payload];

            return false;
        });

        $this->assertSame([[$channelB, 'm3']], $second, $driver . ' 同一实例二次订阅失败');
        $this->assertSame(3, $sub->stats()['received'], $driver . ' 跨会话 received 应累加');
        $this->assertSame(3, $sub->stats()['completed'], $driver . ' 跨会话 completed 应累加');
    }

    /**
     * 模式订阅：回调为 (pattern, channel, payload)，两驱动一致。
     */
    private function scenarioPattern(string $driver): void
    {
        $base = $this->channel($driver . '_pattern');
        $pattern = $base . '.*';
        $channelA = $base . '.a';
        $channelB = $base . '.b';
        $rows = [];

        $sub = $this->pubSub($driver);
        $this->publishLater($driver, [$channelA => ['p1'], $channelB => ['p2']], 0.3);

        $sub->psubscribe([$pattern], function (string ...$args) use (&$rows): ?bool {
            $rows[] = $args;

            return count($rows) >= 2 ? false : null;
        });

        $this->assertSame(
            [[$pattern, $channelA, 'p1'], [$pattern, $channelB, 'p2']],
            $rows,
            $driver . ' 模式订阅消息不匹配'
        );
        $this->assertSame(2, $sub->stats()['completed'], $driver . ' 模式订阅 completed 计数');
        $this->assertSame([], $sub->subscribedPatterns(), $driver . ' 循环结束后模式集合应清空');
    }

    /**
     * publish() 返回订阅端数量；另一条连接发布，不能复用订阅连接。
     */
    private function scenarioPublishReceivers(string $driver): void
    {
        $channel = $this->channel($driver . '_publish_receivers');
        $sub = $this->pubSub($driver);
        $published = new Channel(1);

        Coroutine::create(function () use ($driver, $channel, $published): void {
            try {
                Coroutine::sleep(0.3);
                $publisher = $this->pubSub($driver);
                $published->push(['ok', $publisher->publish($channel, 'hello')]);
            } catch (\Throwable $e) {
                $published->push(['error', get_class($e) . ': ' . $e->getMessage()]);
            }
        });

        $received = [];
        $sub->subscribe([$channel], function (string $ch, string $payload) use (&$received): bool {
            $received[] = [$ch, $payload];

            return false;
        });

        $this->assertSame(['ok', 1], $published->pop(2.0), $driver . ' publish() 应返回订阅端数量 1');
        $this->assertSame([[$channel, 'hello']], $received, $driver . ' 发布/订阅内容不匹配');

        $after = $this->pubSub($driver)->publish($channel, 'no-subscriber');
        $this->assertSame(0, $after, $driver . ' 无订阅者时 publish() 应返回 0');
    }

    /**
     * 协程派发 + 并发上限：peak 不得超过 max_concurrency，stop() + 唤醒消息结束循环。
     */
    private function scenarioCoroutineBackpressure(string $driver): void
    {
        $channel = $this->channel($driver . '_coroutine_backpressure');
        $peak = 0;
        $active = 0;
        $handled = [];
        $childCids = [];
        $mainCid = Coroutine::getCid();

        $sub = $this->pubSub($driver, [
            'dispatch' => AbstractPubSub::DISPATCH_COROUTINE,
            'max_concurrency' => 2,
        ]);

        $payloads = array_map(static fn (int $i): string => 'm' . $i, range(1, 6));
        $this->publishLater($driver, [$channel => $payloads], 0.3, 0.02);
        // 1.2s 后发唤醒消息：此时 6 条消息早已处理完并已请求 stop
        $this->publishLater($driver, [$channel => ['wake']], 1.2);

        $sub->subscribe([$channel], function (string $ch, string $payload) use (
            &$active,
            &$peak,
            &$handled,
            &$childCids,
            $sub,
            $mainCid
        ): void {
            $cid = Coroutine::getCid();
            $childCids[] = $cid;
            $this->assertTrue($cid > 0 && $cid !== $mainCid, '协程派发回调应运行在独立协程');

            $active++;
            $peak = max($peak, $active);
            Coroutine::sleep(0.15);
            $handled[] = $payload;
            $active--;

            if (count($handled) === 6) {
                $sub->stop();
            }
        });

        sort($handled);
        $stats = $sub->stats();
        $this->assertSame($payloads, $handled, $driver . ' 6 条消息应全部完成且不重复');
        $this->assertTrue($peak <= 2, $driver . ' 并发峰值应受 max_concurrency 限制，实际 ' . $peak);
        $this->assertSame(6, $stats['dispatched'], $driver . ' wake 不应被派发');
        $this->assertSame(6, $stats['completed'], $driver . ' completed 计数');
        $this->assertSame(0, $stats['failed'], $driver . ' failed 计数');
        $this->assertSame(0, $stats['in_flight'], $driver . ' 退出时 in_flight 应归零');
        $this->assertSame(7, $stats['received'], $driver . ' received 应包含唤醒消息');
        $this->assertSame(count($payloads), count($childCids), $driver . ' 每条消息一个协程');
    }

    /**
     * 协程派发 + on_error：回调异常只计入 failed，循环继续；不向 subscribe() 抛异常。
     */
    private function scenarioCoroutineOnError(string $driver): void
    {
        $channel = $this->channel($driver . '_coroutine_on_error');
        $errors = [];
        $handled = [];

        $sub = $this->pubSub($driver, [
            'dispatch' => AbstractPubSub::DISPATCH_COROUTINE,
            'max_concurrency' => 1,
            'on_error' => function (\Throwable $e, array $context) use (&$errors): void {
                $errors[] = [$e->getMessage(), $context['channel'] ?? '', $context['payload'] ?? ''];
            },
        ]);

        $this->publishLater($driver, [$channel => ['boom', 'ok']], 0.3, 0.05);
        $this->publishLater($driver, [$channel => ['wake']], 1.0);

        $sub->subscribe([$channel], function (string $ch, string $payload) use (&$handled, $sub): void {
            if ($payload === 'boom') {
                throw new \RuntimeException('handler boom');
            }

            $handled[] = $payload;
            $sub->stop();
        });

        $stats = $sub->stats();
        $this->assertSame([['handler boom', $channel, 'boom']], $errors, $driver . ' on_error 应收到异常与上下文');
        $this->assertSame(['ok'], $handled, $driver . ' on_error 后循环应继续派发');
        $this->assertSame(1, $stats['failed'], $driver . ' failed 计数');
        $this->assertSame(1, $stats['completed'], $driver . ' completed 计数');
        $this->assertSame(0, $stats['in_flight'], $driver . ' 退出时 in_flight 应归零');
        $this->assertFalse($stats['running'], $driver . ' 退出后 running 应为 false');
    }

    /**
     * stop() 可跨协程调用：只登记停止请求，下一条消息到达时循环自行退订退出。
     */
    private function scenarioStopCross(string $driver): void
    {
        $channel = $this->channel($driver . '_stop_cross');
        $sub = $this->pubSub($driver);
        $startedAt = microtime(true);

        Coroutine::create(function () use ($driver, $sub, $channel): void {
            try {
                Coroutine::sleep(0.4);
                $sub->stop();
                $this->pubSub($driver)->publish($channel, 'wake');
            } catch (\Throwable $e) {
                $this->asyncErrors[] = get_class($e) . ': ' . $e->getMessage();
            }
        });

        $sub->subscribe([$channel], static function (): void {
            throw new \RuntimeException('stop 请求后不应再派发消息');
        });

        $elapsed = microtime(true) - $startedAt;
        $this->assertTrue(
            $elapsed >= 0.35 && $elapsed < 5.0,
            sprintf('%s stop() 应在下一条消息到达时退出，实际耗时 %.2fs', $driver, $elapsed)
        );
        $this->assertSame(1, $sub->stats()['received'], $driver . ' 唤醒消息应被计数');
        $this->assertSame(0, $sub->stats()['dispatched'], $driver . ' stop 后不应再派发');
        $this->assertFalse($sub->isRunning(), $driver . ' 退出后 running 应为 false');
        $this->assertFalse($sub->isSubscribed(), $driver . ' 退出后不应残留订阅');
    }

    /**
     * 订阅态的所有冲突入口：publish / 重入 subscribe / 跨协程 unsubscribe。
     */
    private function scenarioConflictGuards(string $driver): void
    {
        $channel = $this->channel($driver . '_conflict');
        $other = $this->channel($driver . '_conflict_other');
        $caught = [];
        $sub = $this->pubSub($driver);

        $this->assertSame(0, $sub->publish($other, 'before-subscribe'), $driver . ' 未订阅时同一实例可以 publish');
        $this->publishLater($driver, [$channel => ['trigger']], 0.2);

        $sub->subscribe([$channel], function (string $ch, string $payload) use ($sub, $other, &$caught): bool {
            $caught['running'] = $sub->isRunning();
            $caught['channels'] = $sub->subscribedChannels();

            try {
                $sub->publish($other, 'conflict');
            } catch (\Throwable $e) {
                $caught['publish'] = $e;
            }

            try {
                $sub->subscribe([$other], static fn () => null);
            } catch (\Throwable $e) {
                $caught['resubscribe'] = $e;
            }

            $done = new Channel(1);
            Coroutine::create(function () use ($sub, $ch, &$caught, $done): void {
                try {
                    $sub->unsubscribe([$ch]);
                } catch (\Throwable $e) {
                    $caught['cross_unsubscribe'] = $e;
                }
                $done->push(true);
            });
            $caught['cross_done'] = $done->pop(1.0);

            return false;
        });

        $this->assertTrue($caught['running'] ?? false, $driver . ' 回调内 running 应为 true');
        $this->assertSame([$channel], $caught['channels'] ?? [], $driver . ' 回调内 subscribedChannels 应包含当前渠道');
        $this->assertInstanceOf(
            SubscriptionConflictException::class,
            $caught['publish'] ?? null,
            $driver . ' 订阅态 publish 应抛 SubscriptionConflictException'
        );
        $this->assertInstanceOf(
            SubscriptionConflictException::class,
            $caught['resubscribe'] ?? null,
            $driver . ' 运行中重入 subscribe 应抛 SubscriptionConflictException'
        );
        $this->assertInstanceOf(
            SubscriptionConflictException::class,
            $caught['cross_unsubscribe'] ?? null,
            $driver . ' 跨协程 unsubscribe 应抛 SubscriptionConflictException'
        );
        $this->assertTrue($caught['cross_done'] ?? false, $driver . ' 跨协程退订应在 1s 内返回异常');
        $this->assertSame(0, $sub->publish($channel, 'after'), $driver . ' 循环结束后已无订阅者');
        $this->expectThrowable(
            InvalidSubscriptionException::class,
            static fn () => $sub->unsubscribe([$channel]),
            'unsubscribe()'
        );
    }

    /**
     * phpredis 空闲订阅：OPT_READ_TIMEOUT=1 时，循环期间必须置为 -1，退出后恢复原值。
     */
    private function scenarioPhpRedisIdleTimeout(): void
    {
        $channel = $this->channel('phpredis_idle');
        $redis = $this->redis();
        $redis->setOption(\Redis::OPT_READ_TIMEOUT, 1);
        $sub = new RedisPubSub($redis);

        $received = [];
        $startedAt = microtime(true);
        $this->publishLater('phpredis', [$channel => ['after-idle']], 2.0);
        $sub->subscribe([$channel], function (string $ch, string $payload) use (&$received): bool {
            $received[] = [$ch, $payload];

            return false;
        });
        $elapsed = microtime(true) - $startedAt;

        $this->assertTrue($elapsed >= 1.8, sprintf('phpredis 空闲时长不足，实际 %.2fs', $elapsed));
        $this->assertSame([[$channel, 'after-idle']], $received, 'phpredis 空闲 2s 后应仍能收到消息');
        $this->assertSame(0, $sub->stats()['failed'], 'phpredis 不应出现读超时失败');
        $this->assertSame(
            1.0,
            (float) $redis->getOption(\Redis::OPT_READ_TIMEOUT),
            'phpredis 循环退出后 OPT_READ_TIMEOUT 应恢复为 1'
        );
    }

    /**
     * predis 空闲订阅：default_socket_timeout=1 时，read_write_timeout=-1 必须生效。
     */
    private function scenarioPredisIdleTimeout(): void
    {
        $channel = $this->channel('predis_idle');
        $previous = ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', '1');

        $received = [];
        $startedAt = microtime(true);
        try {
            $sub = new PredisPubSub($this->predis());
            $this->publishLater('predis', [$channel => ['after-idle']], 3.0);
            $sub->subscribe([$channel], function (string $ch, string $payload) use (&$received): bool {
                $received[] = [$ch, $payload];

                return false;
            });
            $elapsed = microtime(true) - $startedAt;
        } finally {
            ini_set('default_socket_timeout', $previous);
        }

        $this->assertTrue($elapsed >= 2.8, sprintf('predis 空闲时长不足，实际 %.2fs', $elapsed));
        $this->assertSame([[$channel, 'after-idle']], $received, 'predis 空闲 3s 后应仍能收到消息');
        $this->assertSame(0, $sub->stats()['failed'], 'predis 不应出现读超时失败');
    }

    /**
     * 参数与连接类型校验：全部在建立订阅会话前 fail-fast，两驱动同标准。
     */
    private function scenarioInvalidInputs(): void
    {
        $redis = $this->redis();
        $predis = $this->predis();
        $redisSub = new RedisPubSub($redis);
        $predisSub = new PredisPubSub($predis);

        $this->assertFalse($redisSub->stats()['running'], 'phpredis 初始 running 应为 false');
        $this->assertFalse($predisSub->stats()['running'], 'predis 初始 running 应为 false');
        $this->assertSame([], $redisSub->stats()['channels'], 'phpredis 初始渠道应为空');
        $this->assertSame([], $predisSub->stats()['channels'], 'predis 初始渠道应为空');

        foreach (['phpredis' => $redisSub, 'predis' => $predisSub] as $driver => $sub) {
            $this->expectThrowable(InvalidSubscriptionException::class, static fn () => $sub->subscribe([], static fn () => null), 'subscribe()', $driver);
            $this->expectThrowable(InvalidSubscriptionException::class, static fn () => $sub->subscribe([''], static fn () => null), 'subscribe()', $driver);
            $this->expectThrowable(InvalidSubscriptionException::class, static fn () => $sub->psubscribe([], static fn () => null), 'psubscribe()', $driver);
            $this->expectThrowable(InvalidSubscriptionException::class, static fn () => $sub->unsubscribe(['x']), 'unsubscribe()', $driver);
            $this->expectThrowable(InvalidSubscriptionException::class, static fn () => $sub->punsubscribe(['x']), 'punsubscribe()', $driver);
            $this->expectThrowable(InvalidSubscriptionException::class, static fn () => $sub->publish('', 'x'), 'publish()', $driver);
        }

        $this->expectThrowable(PubSubException::class, static fn () => new RedisPubSub($predis), 'RedisPubSub');
        $this->expectThrowable(PubSubException::class, static fn () => new PredisPubSub($redis), 'PredisPubSub');

        $cases = [
            'phpredis' => [$redis, RedisPubSub::class],
            'predis' => [$predis, PredisPubSub::class],
        ];
        foreach ($cases as $driver => [$connection, $class]) {
            foreach ([
                ['dispatch' => 'bogus'],
                ['max_concurrency' => 0],
                ['max_concurrency' => 65537],
                ['on_error' => 'not-callable'],
            ] as $options) {
                $this->expectThrowable(
                    InvalidSubscriptionException::class,
                    static fn () => new $class($connection, $options),
                    (string) array_key_first($options),
                    $driver
                );
            }
        }
    }

    /**
     * @param array<string, list<string>> $plan channel => payloads
     */
    private function publishLater(string $driver, array $plan, float $delay, float $gap = 0.03): void
    {
        Coroutine::create(function () use ($driver, $plan, $delay, $gap): void {
            try {
                Coroutine::sleep($delay);
                $connection = $driver === 'predis' ? $this->predis() : $this->redis();
                foreach ($plan as $channel => $payloads) {
                    foreach ($payloads as $payload) {
                        $connection->publish($channel, $payload);
                        Coroutine::sleep($gap);
                    }
                }
            } catch (\Throwable $e) {
                $this->asyncErrors[] = get_class($e) . ': ' . $e->getMessage();
            }
        });
    }

    private function pubSub(string $driver, array $options = []): AbstractPubSub
    {
        return $driver === 'predis'
            ? new PredisPubSub($this->predis(), $options)
            : new RedisPubSub($this->redis(), $options);
    }

    private function redis(): PhpRedisConnection
    {
        $config = self::redisConfig();

        return (new PhpRedisConnection())->connect($config['host'], $config['port'], 2.0);
    }

    private function predis(): PredisConnection
    {
        $config = SystemEnv::loadDcEnv()['predis'];

        return new PredisConnection([
            'scheme' => $config['scheme'] ?? 'tcp',
            'host' => $config['host'],
            'port' => $config['port'],
        ]);
    }

    /**
     * @return array{host: string, port: int}
     */
    private static function redisConfig(): array
    {
        $config = SystemEnv::loadDcEnv()['redis'];

        return ['host' => (string) $config['host'], 'port' => (int) $config['port']];
    }

    private function channel(string $tag): string
    {
        return sprintf('pubsubv:%s:%d:%s', $tag, getmypid(), bin2hex(random_bytes(3)));
    }

    private function hold(): void
    {
        while (true) {
            Coroutine::sleep(3600);
        }
    }

    private function assertTrue(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    private function assertFalse(bool $condition, string $message): void
    {
        $this->assertTrue(!$condition, $message);
    }

    private function assertSame(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            throw new \RuntimeException(sprintf(
                '%s（期望 %s，实际 %s）',
                $message,
                self::stringify($expected),
                self::stringify($actual)
            ));
        }
    }

    private function assertInstanceOf(string $class, mixed $value, string $message): void
    {
        if (!$value instanceof $class) {
            throw new \RuntimeException(sprintf(
                '%s（期望 %s，实际 %s）',
                $message,
                $class,
                is_object($value) ? get_class($value) : self::stringify($value)
            ));
        }
    }

    private function expectThrowable(
        string $class,
        callable $callback,
        string $messageNeedle = '',
        string $context = ''
    ): \Throwable {
        $prefix = $context === '' ? '' : $context . ' ';

        try {
            $callback();
        } catch (\Throwable $e) {
            $this->assertInstanceOf($class, $e, $prefix . '异常类型不符');
            if ($messageNeedle !== '') {
                $this->assertTrue(
                    str_contains($e->getMessage(), $messageNeedle),
                    sprintf('%s异常信息应包含 "%s"，实际：%s', $prefix, $messageNeedle, $e->getMessage())
                );
            }

            return $e;
        }

        throw new \RuntimeException($prefix . '预期抛出 ' . $class . '，实际未抛出');
    }

    private static function stringify(mixed $value): string
    {
        if (is_object($value)) {
            return get_class($value);
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: var_export($value, true);
    }

    private static function record(string $scenario, string $status, float $elapsed, string $detail): void
    {
        self::log(sprintf('%s scenario=%s elapsed=%.2fs detail=%s', $status, $scenario, $elapsed, $detail));
        self::ensureLogDir();
        file_put_contents(
            self::LOG_DIR . '/results.jsonl',
            json_encode([
                'scenario' => $scenario,
                'status' => $status,
                'elapsed' => round($elapsed, 3),
                'detail' => $detail,
                'pid' => getmypid(),
                'time' => date('c'),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }

    private static function log(string $message): void
    {
        $line = '[PUBSUB-VERIFY] ' . date('H:i:s') . ' ' . $message . PHP_EOL;
        echo $line;
        self::ensureLogDir();
        file_put_contents(self::LOG_DIR . '/verify.log', $line, FILE_APPEND | LOCK_EX);
    }

    private static function ensureLogDir(): void
    {
        if (!is_dir(self::LOG_DIR)) {
            @mkdir(self::LOG_DIR, 0777, true);
        }
    }
}
