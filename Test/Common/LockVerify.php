<?php

namespace Test\Common;

use Malkusch\Lock\Exception\ExecutionOutsideLockException;
use Malkusch\Lock\Exception\LockAcquireTimeoutException;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Http\Client;
use Swoolefy\Core\Application;
use Swoolefy\Core\EventController;
use Swoolefy\Core\Swfy;
use Swoolefy\Library\Lock\MySQLMutex;
use Swoolefy\Library\Lock\PostgreSQLMutex;
use Swoolefy\Library\Lock\RedisMutex;
use Swoolefy\Library\Redis\Redis as PhpRedisConnection;
use Test\App;

/**
 * Lock 模块端到端验证。仅在 Test 服务 Worker 内运行，依赖 SWOOLE_HOOK_ALL 下的协程 IO。
 *
 * 每个场景使用带 run 后缀的独立锁名，避免与演示锁/上一次运行互相干扰；
 * 场景失败只记录错误，不中断后续场景。
 */
final class LockVerify
{
    private const KEY_PREFIX = 'php-malkusch-lock:';

    /** @var string 本次运行的隔离后缀 */
    private string $runId = '';

    public static function run(): array
    {
        return (new self())->execute();
    }

    private function execute(): array
    {
        $this->runId = date('His') . bin2hex(random_bytes(2));

        $scenarios = [
            // 同一请求内 3 个协程（各自独立组件连接）抢同一把锁，临界区不得重叠
            'same_request_coroutines' => 'caseSameRequestCoroutines',
            // 3 个并发 HTTP 请求各自持锁，验证跨 Worker/请求互斥
            'cross_request' => 'caseCrossRequest',
            // 临界区耗时超过租期：释放时抛 ExecutionOutsideLockException，且键被删除
            'lease_expired' => 'caseLeaseExpired',
            // 短等待范式：锁被占用时在 acquireTimeout 内失败，走「稍后重试」分支
            'short_wait' => 'caseShortWait',
            // phpredis 持锁、predis 竞争：两个驱动使用同一 key 互斥
            'mixed_drivers' => 'caseMixedDrivers',
            // 业务异常穿透后锁必须释放，随后可立即重新获取
            'exception_release' => 'caseExceptionRelease',
            // synchronized 在 goApp 子协程执行回调，并建立独立的 EventController 上下文
            'synchronized_context' => 'caseSynchronizedContext',
            // 同一协程复用组件单例，子协程通过独立 EventController 获得新实例
            'component_instances' => 'caseComponentInstances',
            // swoolefy Redis 包装器自动解包：NX/PX 与释放脚本均走 phpredis 分支
            'wrapper_unwrap' => 'caseWrapperUnwrap',
            // MySQL GET_LOCK：子协程执行、业务异常透传、释放后可重入
            'mysql_synchronized' => 'caseMysqlSynchronized',
            // PostgreSQL advisory lock：子协程执行、业务异常透传、释放后可重入
            'pgsql_synchronized' => 'casePgsqlSynchronized',
        ];

        $cases = [];
        foreach ($scenarios as $name => $method) {
            $startedAt = microtime(true);
            try {
                $detail = $this->{$method}();
                $cases[] = [
                    'name'       => $name,
                    'pass'       => true,
                    'skipped'    => (bool) ($detail['skipped'] ?? false),
                    'elapsed_ms' => round((microtime(true) - $startedAt) * 1000, 1),
                    'detail'     => $detail,
                ];
            } catch (\Throwable $e) {
                $cases[] = [
                    'name'       => $name,
                    'pass'       => false,
                    'elapsed_ms' => round((microtime(true) - $startedAt) * 1000, 1),
                    'error'      => get_class($e) . ': ' . $e->getMessage(),
                ];
            }
        }

        $pass = !in_array(false, array_column($cases, 'pass'), true);
        return ['pass' => $pass, 'run' => $this->runId, 'cases' => $cases];
    }

    private function caseSameRequestCoroutines(): array
    {
        $name = $this->freshName('same_request');
        $this->cleanup($name);

        $events = new Channel(3);
        $cids = [];
        for ($i = 0; $i < 3; $i++) {
            $cids[] = goApp(function ($event) use ($i, $name, $events): void {
                try {
                    // 子协程的 App 有自己的组件容器，从连接池取到独立连接
                    $lock = new RedisMutex(App::getRedis()->getObject(), $name, 5.0, 10.0);
                    $lock->synchronized(function () use ($i, $events): void {
                        $enteredAt = microtime(true);
                        Coroutine::sleep(0.15);
                        $events->push(['index' => $i, 'entered_at' => $enteredAt, 'left_at' => microtime(true)]);
                    });
                } catch (\Throwable $e) {
                    $events->push(['error' => get_class($e) . ': ' . $e->getMessage()]);
                }
            });
        }
        Coroutine::join($cids, 10.0);

        $spans = $this->collectChannel($events, 3, '同请求协程结果缺失');
        usort($spans, static fn(array $a, array $b): int => $a['entered_at'] <=> $b['entered_at']);
        for ($i = 1; $i < 3; $i++) {
            $this->assertTrue(
                $spans[$i]['entered_at'] >= $spans[$i - 1]['left_at'],
                '同请求协程临界区发生重叠'
            );
        }

        $this->cleanup($name);
        return [
            'coroutines' => 3,
            'span_ms'    => round(($spans[2]['left_at'] - $spans[0]['entered_at']) * 1000, 1),
        ];
    }

    private function caseCrossRequest(): array
    {
        $name = $this->freshName('cross_request');
        $this->cleanup($name);

        $port = (int) (Swfy::getConf()['port'] ?? 9501);
        $total = 3;
        $results = new Channel($total);
        $cids = [];
        for ($i = 0; $i < $total; $i++) {
            $cids[] = Coroutine::create(function () use ($name, $port, $results): void {
                $client = new Client('127.0.0.1', $port);
                $client->set(['timeout' => 10]);
                try {
                    $client->get('/api/lock/hold?' . http_build_query([
                        'name'          => $name,
                        'hold_ms'       => 200,
                        'wait_seconds'  => 5,
                    ]));
                    $body = (string) $client->getBody();
                    $payload = json_decode($body, true);
                    $results->push(['data' => $payload['data'] ?? null, 'body' => $body]);
                } catch (\Throwable $e) {
                    $results->push(['error' => get_class($e) . ': ' . $e->getMessage()]);
                } finally {
                    $client->close();
                }
            });
        }
        Coroutine::join($cids, 15.0);

        $spans = [];
        foreach ($this->collectChannel($results, $total, '跨请求结果缺失') as $item) {
            $this->assertTrue(
                is_array($item['data'] ?? null) && ($item['data']['acquired'] ?? false) === true,
                '跨请求未拿到锁：' . substr((string) ($item['body'] ?? ''), 0, 200)
            );
            $spans[] = $item['data'];
        }
        usort($spans, static fn(array $a, array $b): int => $a['entered_at'] <=> $b['entered_at']);
        for ($i = 1; $i < $total; $i++) {
            $this->assertTrue(
                $spans[$i]['entered_at'] >= $spans[$i - 1]['left_at'],
                '跨请求临界区发生重叠'
            );
        }

        $this->cleanup($name);
        return [
            'requests' => $total,
            'span_ms'  => round(($spans[$total - 1]['left_at'] - $spans[0]['entered_at']) * 1000, 1),
        ];
    }

    private function caseLeaseExpired(): array
    {
        $name = $this->freshName('lease_expired');
        $this->cleanup($name);

        $lock = new RedisMutex($this->redis(), $name, 2.0, 0.2);
        try {
            $lock->synchronized(function () {
                Coroutine::sleep(0.5);
                return 'lease-result';
            });
            $this->assertTrue(false, '临界区超过租期但未抛 ExecutionOutsideLockException');
        } catch (ExecutionOutsideLockException $e) {
            $this->assertTrue($e->getCodeResult() === 'lease-result', '租期越界异常未保留临界区返回值');
        }

        $this->assertTrue(!$this->redis()->exists($this->key($name)), '租期越界后锁键仍残留');
        $this->cleanup($name);
        return ['lease_seconds' => 0.2, 'critical_ms' => 500];
    }

    private function caseShortWait(): array
    {
        $name = $this->freshName('short_wait');
        $this->cleanup($name);

        $held = new Channel(1);
        $cid = goApp(function ($event) use ($name, $held): void {
            try {
                $lock = new RedisMutex(App::getRedis()->getObject(), $name, 3.0, 10.0);
                $lock->synchronized(function () use ($held): void {
                    $held->push(true);
                    Coroutine::sleep(1.0);
                });
            } catch (\Throwable $e) {
                $held->push($e);
            }
        });

        $signal = $held->pop(3.0);
        if ($signal instanceof \Throwable) {
            Coroutine::join([$cid], 5.0);
            throw $signal;
        }
        $this->assertTrue($signal === true, '持锁协程未能进入临界区');

        $startedAt = microtime(true);
        $timedOut = false;
        try {
            (new RedisMutex($this->redis(), $name, 0.25, 10.0))->synchronized(static fn() => null);
        } catch (LockAcquireTimeoutException $e) {
            $timedOut = true;
        }
        $elapsed = microtime(true) - $startedAt;

        Coroutine::join([$cid], 5.0);
        $this->assertTrue($timedOut, '锁被占用时短等待未超时');
        $this->assertTrue($elapsed < 0.8, sprintf('短等待耗时异常：%.3fs', $elapsed));

        // 释放后立即可获取，验证前面的超时没有留下脏状态
        $acquired = false;
        (new RedisMutex($this->redis(), $name, 1.0, 10.0))->synchronized(function () use (&$acquired): void {
            $acquired = true;
        });
        $this->assertTrue($acquired, '持锁协程释放后未能重新获取锁');

        $this->cleanup($name);
        return ['acquire_timeout_ms' => 250, 'failed_after_ms' => round($elapsed * 1000, 1)];
    }

    private function caseMixedDrivers(): array
    {
        $name = $this->freshName('mixed_drivers');
        $this->cleanup($name);

        $held = new Channel(1);
        $cid = goApp(function ($event) use ($name, $held): void {
            try {
                $lock = new RedisMutex(App::getRedis()->getObject(), $name, 3.0, 10.0);
                $lock->synchronized(function () use ($held): void {
                    $held->push(true);
                    Coroutine::sleep(0.8);
                });
            } catch (\Throwable $e) {
                $held->push($e);
            }
        });

        $signal = $held->pop(3.0);
        if ($signal instanceof \Throwable) {
            Coroutine::join([$cid], 5.0);
            throw $signal;
        }
        $this->assertTrue($signal === true, 'phpredis 持锁协程未能进入临界区');

        $timedOut = false;
        try {
            (new RedisMutex($this->predis(), $name, 0.25, 10.0))->synchronized(static fn() => null);
        } catch (LockAcquireTimeoutException $e) {
            $timedOut = true;
        }
        Coroutine::join([$cid], 5.0);
        $this->assertTrue($timedOut, 'predis 未能感知 phpredis 持有的锁');

        $acquiredByPredis = false;
        (new RedisMutex($this->predis(), $name, 1.0, 10.0))->synchronized(function () use (&$acquiredByPredis): void {
            $acquiredByPredis = true;
        });
        $this->assertTrue($acquiredByPredis, 'phpredis 释放后 predis 未能获取锁');

        $this->cleanup($name);
        return ['holder' => 'phpredis', 'contender' => 'predis'];
    }

    private function caseExceptionRelease(): array
    {
        $name = $this->freshName('exception_release');
        $this->cleanup($name);

        $caught = false;
        try {
            (new RedisMutex($this->redis(), $name, 1.0, 10.0))->synchronized(function (): void {
                throw new \DomainException('expected-business-error');
            });
        } catch (\DomainException $e) {
            $caught = $e->getMessage() === 'expected-business-error';
        }
        $this->assertTrue($caught, '业务异常未原样透传');
        $this->assertTrue(!$this->redis()->exists($this->key($name)), '业务异常后锁键未释放');

        $acquired = false;
        (new RedisMutex($this->redis(), $name, 1.0, 10.0))->synchronized(function () use (&$acquired): void {
            $acquired = true;
        });
        $this->assertTrue($acquired, '业务异常释放后无法重新获取锁');

        $this->cleanup($name);
        return ['exception' => \DomainException::class];
    }

    private function caseSynchronizedContext(): array
    {
        $name = $this->freshName('sync_context');
        $this->cleanup($name);

        $parentCid = Coroutine::getCid();
        $observed = [];
        $result = (new RedisMutex($this->redis(), $name, 1.0, 10.0))->synchronized(
            function () use (&$observed): string {
                $app = Application::getApp();
                $observed = [
                    'cid' => Coroutine::getCid(),
                    'app' => is_object($app) ? get_class($app) : get_debug_type($app),
                ];

                return 'context-result';
            }
        );

        $this->assertTrue($result === 'context-result', '子协程回调返回值未回传给调用方');
        $this->assertTrue($observed['cid'] !== $parentCid, '临界区回调未在子协程执行');
        $this->assertTrue(
            $observed['app'] === EventController::class,
            '子协程未建立独立的 swoolefy App 上下文：' . $observed['app']
        );
        $this->assertTrue(!$this->redis()->exists($this->key($name)), '上下文场景释放后锁键残留');

        $this->cleanup($name);
        return ['parent_cid' => $parentCid, 'callback_cid' => $observed['cid'], 'app' => $observed['app']];
    }

    private function caseComponentInstances(): array
    {
        $this->cleanup('order_lock');

        $first = App::getRedisLock();
        $second = App::getRedisLock();
        $firstMutex = $this->unwrapDto($first);
        $secondMutex = $this->unwrapDto($second);

        $this->assertTrue($firstMutex instanceof RedisMutex, '组件未返回 RedisMutex：' . get_debug_type($firstMutex));
        $this->assertTrue($secondMutex instanceof RedisMutex, '组件未返回 RedisMutex：' . get_debug_type($secondMutex));
        $this->assertTrue(
            spl_object_id($firstMutex) === spl_object_id($secondMutex),
            '同一协程两次 getRedisLock() 未复用同一个 mutex 实例'
        );

        // token/acquireTs 在每次 release 后清空，同一实例顺序获取两次应各自成功
        $firstMutex->synchronized(static fn() => null);
        $secondMutex->synchronized(static fn() => null);

        $childChannel = new Channel(1);
        goApp(function ($event) use ($childChannel): void {
            $childChannel->push(spl_object_id($this->unwrapDto(App::getRedisLock())));
        });
        $childMutexId = $childChannel->pop(1);

        $this->assertTrue(is_int($childMutexId), '子协程未返回 mutex 实例 id');
        $this->assertTrue(
            $childMutexId !== spl_object_id($firstMutex),
            '子协程与父协程共用了同一个 mutex 实例'
        );

        $this->cleanup('order_lock');
        return ['parent_reused' => true, 'child_distinct' => true];
    }

    private function caseWrapperUnwrap(): array
    {
        $name = $this->freshName('wrapper_unwrap');
        $this->cleanup($name);

        $redis = $this->redis();
        $this->assertTrue($redis instanceof PhpRedisConnection, 'redis 组件不是 swoolefy 包装器');

        $observed = [];
        (new RedisMutex($redis, $name, 1.0, 5.0))->synchronized(function () use ($redis, $name, &$observed): void {
            $observed['ttl_ms'] = $redis->pttl($this->key($name));
            $observed['token'] = (string) $redis->get($this->key($name));
        });

        // PX 由秒换算毫秒并向上取整，允许 1ms 级误差
        $this->assertTrue(
            is_int($observed['ttl_ms']) && $observed['ttl_ms'] >= 4900 && $observed['ttl_ms'] <= 5100,
            '锁键 TTL 不在租期范围内：' . var_export($observed['ttl_ms'], true)
        );
        $this->assertTrue(strlen($observed['token']) === 64, 'token 长度异常：' . strlen($observed['token']));
        $this->assertTrue(!$redis->exists($this->key($name)), '释放后锁键仍残留');

        $this->cleanup($name);
        return ['ttl_ms' => $observed['ttl_ms'], 'token_length' => strlen($observed['token'])];
    }

    private function caseMysqlSynchronized(): array
    {
        try {
            $db = App::getDb()->getObject();
            if (!$db->getPdo() instanceof \PDO) {
                $db->connect();
            }
        } catch (\Throwable) {
            return ['skipped' => true, 'reason' => 'MySQL is unreachable'];
        }

        return $this->caseSqlMutexSynchronized(
            'mysql',
            static function (string $name, float $acquireTimeout): MySQLMutex {
                $db = App::getDb()->getObject();
                if (!$db->getPdo() instanceof \PDO) {
                    $db->connect();
                }

                return new MySQLMutex($db, $name, $acquireTimeout);
            }
        );
    }

    private function casePgsqlSynchronized(): array
    {
        try {
            $db = App::getPgSql()->getObject();
            if (!$db->getPdo() instanceof \PDO) {
                $db->connect();
            }
        } catch (\Throwable) {
            return ['skipped' => true, 'reason' => 'PostgreSQL is unreachable'];
        }

        return $this->caseSqlMutexSynchronized(
            'pgsql',
            static function (string $name, float $acquireTimeout): PostgreSQLMutex {
                $db = App::getPgSql()->getObject();
                if (!$db->getPdo() instanceof \PDO) {
                    $db->connect();
                }

                return new PostgreSQLMutex($db, $name, $acquireTimeout);
            }
        );
    }

    /**
     * MySQL / PostgreSQL 共同的 synchronized 契约：回调在子协程执行、返回值回传、
     * 业务异常原样透传、正常结束与异常结束都必须释放锁。
     */
    private function caseSqlMutexSynchronized(string $label, callable $factory): array
    {
        $name = $this->freshName($label . '_sync');
        $parentCid = Coroutine::getCid();
        $callbackCid = null;

        $result = $factory($name, 2.0)->synchronized(function () use (&$callbackCid): string {
            $callbackCid = Coroutine::getCid();

            return 'sql-result';
        });
        $this->assertTrue($callbackCid !== null && $callbackCid !== $parentCid, $label . ' 回调未在子协程执行');
        $this->assertTrue($result === 'sql-result', $label . ' 子协程返回值未回传');

        $reacquired = false;
        $factory($name, 0.5)->synchronized(function () use (&$reacquired): void {
            $reacquired = true;
        });
        $this->assertTrue($reacquired, $label . ' synchronized 返回后锁未释放');

        $caught = false;
        try {
            $factory($name, 2.0)->synchronized(static function (): void {
                throw new \DomainException('expected-business-error');
            });
        } catch (\DomainException $e) {
            $caught = $e->getMessage() === 'expected-business-error';
        }
        $this->assertTrue($caught, $label . ' 业务异常未原样透传');

        $reacquired = false;
        $factory($name, 0.5)->synchronized(function () use (&$reacquired): void {
            $reacquired = true;
        });
        $this->assertTrue($reacquired, $label . ' 业务异常后锁未释放');

        return ['parent_cid' => $parentCid, 'callback_cid' => $callbackCid];
    }

    /**
     * @return \Swoolefy\Library\Redis\Redis
     */
    private function redis()
    {
        return App::getRedis()->getObject();
    }

    /**
     * @return \Swoolefy\Library\Redis\Predis
     */
    private function predis()
    {
        return App::getPredis()->getObject();
    }

    private function unwrapDto($component): object
    {
        return is_object($component) && method_exists($component, 'getObject')
            ? $component->getObject()
            : $component;
    }

    /**
     * @return list<array>
     */
    private function collectChannel(Channel $channel, int $count, string $message): array
    {
        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $item = $channel->pop(0.5);
            $this->assertTrue(is_array($item), $message);
            if (isset($item['error'])) {
                throw new \RuntimeException($item['error']);
            }
            $items[] = $item;
        }
        return $items;
    }

    private function freshName(string $case): string
    {
        return $case . '_' . $this->runId;
    }

    private function key(string $name): string
    {
        return self::KEY_PREFIX . $name;
    }

    private function cleanup(string ...$names): void
    {
        try {
            $this->redis()->del(...array_map(fn(string $name): string => $this->key($name), $names));
        } catch (\Throwable $e) {
            // 清理失败不掩盖场景本身的断言结果
        }
    }

    private function assertTrue(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }
}
