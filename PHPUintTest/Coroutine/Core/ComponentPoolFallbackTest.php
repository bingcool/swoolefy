<?php

declare(strict_types=1);

namespace PHPUintTest\Coroutine\Core;

use PHPUintTest\CoroutineTestCase;
use ReflectionProperty;
use RuntimeException;
use Swoolefy\Core\BaseServer;
use Swoolefy\Core\ComponentTrait;
use Swoolefy\Core\Coroutine\CoroutinePools;
use Swoolefy\Core\Coroutine\PoolFallbackLease;
use Swoolefy\Exception\ComponentPoolExhaustedException;
use Swoolefy\Exception\SystemException;

/**
 * ComponentTrait 池耗尽后的 fallback 配额与立即拒绝。
 */
final class ComponentPoolFallbackTest extends CoroutineTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetCoroutinePools();
        BaseServer::setAppConf([
            'components' => [],
            'component_pools' => [],
        ]);
    }

    protected function tearDown(): void
    {
        $this->resetCoroutinePools();
        parent::tearDown();
    }

    public function testExplicitQuotaRejectsImmediatelyAfterPoolWait(): void
    {
        $this->runInCoroutine(function (): void {
            $pool = $this->bootPool('db', 2, ['enabled' => true, 'max_concurrent' => 1]);
            $holders = [];
            $holders[] = $this->newHost()->get('db');
            $holders[] = $this->newHost()->get('db');
            $fallbackHost = $this->newHost();
            $fallback = $fallbackHost->get('db');
            $this->assertNotContains(
                spl_object_id($fallback),
                $fallbackHost->pooledObjIds(),
                'fallback must not enter componentPoolsObjIds',
            );
            $this->assertInstanceOf(PoolFallbackLease::class, $fallback->__fallbackLease);
            $this->assertSame(1, $pool->getFallbackInflight());

            $started = microtime(true);
            try {
                $this->newHost()->get('db');
                $this->fail('quota exhausted must throw');
            } catch (ComponentPoolExhaustedException $e) {
                $elapsed = microtime(true) - $started;
                $this->assertLessThan(0.5, $elapsed, 'must not wait again after fallback quota exhausted');
                $this->assertTrue(str_contains($e->getMessage(), '连接池[db]已达到最大上限'));
                $this->assertSame(3, $e->getContextData()['total_cap'] ?? null);
            }
            unset($holders);
        });
    }

    public function testDefaultMaxConcurrentIsTwicePoolSize(): void
    {
        $this->runInCoroutine(function (): void {
            $pool = $this->bootPool('db', 2, []);
            $this->assertSame(4, $pool->getFallbackMax());

            $holders = [];
            for ($i = 0; $i < 6; ++$i) {
                $holders[] = $this->newHost()->get('db');
            }
            $this->assertSame(4, $pool->getFallbackInflight());

            try {
                $this->newHost()->get('db');
                $this->fail('7th get must reject');
            } catch (ComponentPoolExhaustedException $e) {
                $this->assertSame(6, $e->getContextData()['total_cap'] ?? null);
            }
            unset($holders);
        });
    }

    public function testClearComponentReleasesFallbackQuota(): void
    {
        $this->runInCoroutine(function (): void {
            $pool = $this->bootPool('db', 1, ['enabled' => true, 'max_concurrent' => 1]);
            $pooled = $this->newHost();
            $pooled->get('db');
            $fallback = $this->newHost();
            $fallback->get('db');
            $this->assertSame(1, $pool->getFallbackInflight());

            $fallback->clearAll();
            $this->assertSame(0, $pool->getFallbackInflight());
            $again = $this->newHost();
            $again->get('db');
            $this->assertSame(1, $pool->getFallbackInflight());
            unset($pooled, $again);
        });
    }

    public function testCreatObjectFailureRollsBackQuota(): void
    {
        $this->runInCoroutine(function (): void {
            $created = 0;
            $pool = $this->bootPool('db', 1, ['enabled' => true, 'max_concurrent' => 1], function () use (&$created): object {
                ++$created;
                if ($created > 1) {
                    throw new RuntimeException('handshake failed');
                }

                return new \stdClass();
            });

            $holder = $this->newHost();
            $holder->get('db');
            try {
                $this->newHost()->get('db');
                $this->fail('handshake failure must bubble');
            } catch (RuntimeException $e) {
                $this->assertSame('handshake failed', $e->getMessage());
            }
            $this->assertSame(0, $pool->getFallbackInflight());
            unset($holder);
        });
    }

    public function testDisabledFallbackRejectsWithoutExtraConstruct(): void
    {
        $this->runInCoroutine(function (): void {
            $created = 0;
            $this->bootPool('db', 1, ['enabled' => false], function () use (&$created): object {
                ++$created;

                return new \stdClass();
            });
            $holder = $this->newHost();
            $holder->get('db');
            $afterPool = $created;
            try {
                $this->newHost()->get('db');
                $this->fail('disabled fallback must 503');
            } catch (ComponentPoolExhaustedException $e) {
                $this->assertSame(0, $e->getContextData()['fallback_max'] ?? null);
            }
            $this->assertSame($afterPool, $created);
            unset($holder);
        });
    }

    public function testSameCoroutineGetDoesNotReserveTwice(): void
    {
        $this->runInCoroutine(function (): void {
            $pool = $this->bootPool('db', 1, ['enabled' => true, 'max_concurrent' => 1]);
            $holder = $this->newHost();
            $first = $holder->get('db');
            $this->assertSame(0, $pool->getFallbackInflight(), 'first get should hit the pool');
            $second = $holder->get('db');
            $this->assertSame($first, $second);
            $this->assertSame(0, $pool->getFallbackInflight());

            $fallbackHost = $this->newHost();
            $fallbackFirst = $fallbackHost->get('db');
            $this->assertSame(1, $pool->getFallbackInflight());
            $fallbackSecond = $fallbackHost->get('db');
            $this->assertSame($fallbackFirst, $fallbackSecond);
            $this->assertSame(1, $pool->getFallbackInflight(), 'same cid must not reserve fallback twice');
            unset($holder, $fallbackHost);
        });
    }

    public function testDbQuotaDoesNotBlockRedisFallback(): void
    {
        $this->runInCoroutine(function (): void {
            $db = $this->bootPool('db', 1, ['enabled' => true, 'max_concurrent' => 0]);
            $redis = $this->bootPool('redis', 1, ['enabled' => true, 'max_concurrent' => 1]);
            $dbHolder = $this->newHost();
            $dbHolder->get('db');
            try {
                $this->newHost()->get('db');
                $this->fail('db fallback disabled');
            } catch (ComponentPoolExhaustedException) {
            }

            $redisHolder = $this->newHost();
            $redisHolder->get('redis');
            $fallback = $this->newHost();
            $fallback->get('redis');
            $this->assertSame(1, $redis->getFallbackInflight());
            $this->assertSame(0, $db->getFallbackInflight());
            unset($dbHolder, $redisHolder, $fallback);
        });
    }

    public function testMissingPoolHandlerThrowsSystemException(): void
    {
        $this->runInCoroutine(function (): void {
            BaseServer::setAppConf([
                'components' => [
                    'ghost' => static fn (): object => new \stdClass(),
                ],
                'component_pools' => [
                    'ghost' => ['max_pool_num' => 1],
                ],
            ]);
            try {
                $this->newHost()->get('ghost');
                $this->fail('missing handler must not creatObject unbounded');
            } catch (SystemException $e) {
                $this->assertTrue(str_contains($e->getMessage(), 'has no PoolsHandler'));
            }
        });
    }

    public function testNegativeMaxConcurrentRejectedAtAddPool(): void
    {
        $this->expectException(SystemException::class);
        $this->runInCoroutine(function (): void {
            CoroutinePools::getInstance()->addPool('bad', [
                'max_pool_num' => 1,
                'fallback' => ['max_concurrent' => -1],
            ], static fn (): object => new \stdClass());
        });
    }

    /**
     * @param array<string, mixed> $fallback
     * @param callable(): object|null $constructor
     */
    private function bootPool(string $name, int $maxPoolNum, array $fallback, ?callable $constructor = null): \Swoolefy\Core\Coroutine\PoolsHandler
    {
        $ctor = $constructor ?? static fn (): object => new \stdClass();
        $conf = [
            'max_pool_num' => $maxPoolNum,
            'max_pop_timeout' => 0.02,
            'max_push_timeout' => 0.02,
            'max_life_timeout' => 3600,
        ];
        if ($fallback !== []) {
            $conf['fallback'] = $fallback;
        }

        $appConf = BaseServer::getAppConf();
        $components = is_array($appConf['components'] ?? null) ? $appConf['components'] : [];
        $pools = is_array($appConf['component_pools'] ?? null) ? $appConf['component_pools'] : [];
        $components[$name] = $ctor;
        $pools[$name] = $conf;
        BaseServer::setAppConf([
            'components' => $components,
            'component_pools' => $pools,
        ]);

        CoroutinePools::getInstance()->addPool($name, $conf, $ctor);
        $pool = CoroutinePools::getInstance()->getPool($name);
        $this->assertNotNull($pool);

        return $pool;
    }

    private function newHost(): FallbackPoolHost
    {
        return new FallbackPoolHost();
    }

    private function resetCoroutinePools(): void
    {
        $ref = new ReflectionProperty(CoroutinePools::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, null);
    }
}

final class FallbackPoolHost
{
    use ComponentTrait;

    /**
     * @return list<int>
     */
    public function pooledObjIds(): array
    {
        return array_values($this->componentPoolsObjIds);
    }

    public function clearAll(): void
    {
        $this->clearComponent(null, true);
    }
}
