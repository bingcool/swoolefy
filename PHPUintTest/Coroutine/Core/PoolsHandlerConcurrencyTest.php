<?php

declare(strict_types=1);

namespace PHPUintTest\Coroutine\Core;

use PHPUintTest\CoroutineTestCase;
use ReflectionProperty;
use RuntimeException;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoolefy\Core\Coroutine\PoolsHandler;

/**
 * PoolsHandler 的协程容量一致性回归测试。
 *
 * 测试直接调用 PoolsHandler，避免 ComponentTrait 在 fetch 失败时的新建兜底
 * 掩盖池自身的容量、超时或恢复问题。等待均由 Channel 或明确状态驱动，
 * 仅在最终异步 push/refill 收敛时使用带截止时间的短暂让出。
 */
final class PoolsHandlerConcurrencyTest extends CoroutineTestCase
{
    /**
     * 构造器在 yield 时，所有协程共享同一套预留容量，不能重复 warm-up。
     */
    public function testConstructorYieldNeverCreatesMoreThanCapacity(): void
    {
        PoolHandlerTestResource::reset();
        $this->runInCoroutine(function (): void {
            $capacity = 5;
            $coroutines = 1000;
            $enteredConstructor = new Channel($coroutines);
            $constructionPermit = new Channel($coroutines * $capacity);
            $completed = new Channel($coroutines);
            $errors = [];
            $pool = $this->createPool(
                $capacity,
                static function () use ($enteredConstructor, $constructionPermit): PoolHandlerTestResource {
                    $enteredConstructor->push(true);
                    if (!$constructionPermit->pop(5.0)) {
                        throw new RuntimeException('测试构造器未收到协调信号。');
                    }

                    return new PoolHandlerTestResource();
                },
            );

            for ($i = 0; $i < $coroutines; ++$i) {
                Coroutine::create(static function () use ($pool, $completed, &$errors): void {
                    try {
                        $object = $pool->fetchObj();
                        if (!is_object($object)) {
                            throw new RuntimeException('并发 fetch 返回 null。');
                        }

                        $pool->pushObj($object);
                    } catch (\Throwable $throwable) {
                        $errors[] = $throwable->getMessage();
                    } finally {
                        $completed->push(true);
                    }
                });
            }

            // 固定等待首批构造器进入，随后一次性放行所有可能的旧实现重复构造。
            for ($i = 0; $i < $capacity; ++$i) {
                self::assertTrue((bool) $enteredConstructor->pop(5.0));
            }
            for ($i = 0; $i < $coroutines * $capacity; ++$i) {
                $constructionPermit->push(true);
            }
            $this->awaitCompleted($completed, $coroutines);

            self::assertSame([], $errors);
            $this->assertRecovered($pool, $capacity);
            self::assertSame($capacity, PoolHandlerTestResource::$created);
            self::assertLessThanOrEqual($capacity, PoolHandlerTestResource::$peakAlive);
            self::assertSame($capacity, $this->getProtectedInt($pool, 'objectCount'));
        });
    }

    /**
     * 多协程同时持有、同时归还并重复借还时，池必须无饥饿且最终恢复满水位。
     */
    public function testConcurrentRepeatedCheckoutAndReturnRecoversPool(): void
    {
        PoolHandlerTestResource::reset();
        $this->runInCoroutine(function (): void {
            $capacity = 2;
            $workers = 20;
            $rounds = 10;
            $held = new Channel($workers);
            $release = new Channel($workers);
            $completed = new Channel($workers);
            $errors = [];
            $pool = $this->createPool($capacity, static fn (): PoolHandlerTestResource => new PoolHandlerTestResource());

            for ($i = 0; $i < $workers; ++$i) {
                Coroutine::create(static function () use ($pool, $rounds, $held, $release, $completed, &$errors): void {
                    try {
                        for ($round = 0; $round < $rounds; ++$round) {
                            $object = $pool->fetchObj();
                            if (!is_object($object)) {
                                throw new RuntimeException('重复 fetch 发生资源饥饿。');
                            }

                            if ($round === 0) {
                                $held->push(true);
                                if (!$release->pop(5.0)) {
                                    throw new RuntimeException('测试协程未收到归还信号。');
                                }
                            }

                            $pool->pushObj($object);
                        }
                    } catch (\Throwable $throwable) {
                        $errors[] = $throwable->getMessage();
                    } finally {
                        $completed->push(true);
                    }
                });
            }

            // 至少两个对象被确定持有后，再放行首轮归还，保证所有 worker 竞争同一池。
            for ($i = 0; $i < $capacity; ++$i) {
                self::assertTrue((bool) $held->pop(5.0));
            }
            for ($i = 0; $i < $workers; ++$i) {
                $release->push(true);
            }
            $this->awaitCompleted($completed, $workers);

            self::assertSame([], $errors);
            $this->assertRecovered($pool, $capacity);
            self::assertLessThanOrEqual($capacity, PoolHandlerTestResource::$peakAlive);
            self::assertSame($capacity, $this->getProtectedInt($pool, 'objectCount'));
        });
    }

    /**
     * 已满池的第二次 fetch 必须按 pop timeout 失败；构造器异常则必须回滚预留容量。
     */
    public function testTimeoutAndConstructorFailureDoNotLeakCapacityReservation(): void
    {
        PoolHandlerTestResource::reset();
        $this->runInCoroutine(function (): void {
            $attempts = 0;
            $pool = $this->createPool(1, static function () use (&$attempts): PoolHandlerTestResource {
                ++$attempts;
                if ($attempts === 1) {
                    throw new RuntimeException('构造失败');
                }

                return new PoolHandlerTestResource();
            }, 0.01);

            try {
                $pool->fetchObj();
                self::fail('首次构造应抛出异常。');
            } catch (RuntimeException $exception) {
                self::assertSame('构造失败', $exception->getMessage());
            }
            self::assertSame(0, $this->getProtectedInt($pool, 'objectCount'));

            $object = $pool->fetchObj();
            self::assertIsObject($object);
            self::assertSame(1, $this->getProtectedInt($pool, 'objectCount'));

            // 资源仍被持有时，下一次 fetch 只能因 pop timeout 返回 null，不能额外创建。
            self::assertNull($pool->fetchObj());
            self::assertSame(1, $this->getProtectedInt($pool, 'objectCount'));

            $pool->pushObj($object);
            $this->assertRecovered($pool, 1);
            self::assertSame(1, PoolHandlerTestResource::$created);
        });
    }

    /**
     * 过期丢弃与 clearPool 都必须释放 objectCount，后续 fetch 才能重建资源。
     */
    public function testExpiredReturnAndClearPoolReleaseLifecycleCapacity(): void
    {
        PoolHandlerTestResource::reset();
        $this->runInCoroutine(function (): void {
            $pool = $this->createPool(2, static fn (): PoolHandlerTestResource => new PoolHandlerTestResource());
            $object = $pool->fetchObj();
            self::assertIsObject($object);

            // 人为标记为过期，覆盖 pushObj 的丢弃 + refill 容量账本路径。
            $object->__objExpireTime = time() - 1;
            $pool->pushObj($object);
            $this->assertRecovered($pool, 2);
            self::assertSame(2, $this->getProtectedInt($pool, 'objectCount'));

            $pool->clearPool();
            self::assertSame(0, $pool->getCurrentNum());
            self::assertSame(0, $this->getProtectedInt($pool, 'objectCount'));

            $replacement = $pool->fetchObj();
            self::assertIsObject($replacement);
            $pool->pushObj($replacement);
            $this->assertRecovered($pool, 2);
            self::assertSame(2, $this->getProtectedInt($pool, 'objectCount'));
        });
    }

    /**
     * 两个不同 owner 在屏障后同时归还各自连接时，每个 lease 只能结算一次。
     */
    public function testDistinctOwnersCanReturnSimultaneouslyWithoutBalanceError(): void
    {
        PoolHandlerTestResource::reset();
        $this->runInCoroutine(function (): void {
            $capacity = 2;
            $ready = new Channel($capacity);
            $release = new Channel($capacity);
            $completed = new Channel($capacity);
            $resourceIds = [];
            $pool = $this->createPool($capacity, static fn (): PoolHandlerTestResource => new PoolHandlerTestResource());

            for ($i = 0; $i < $capacity; ++$i) {
                Coroutine::create(static function () use ($pool, $ready, $release, $completed, &$resourceIds): void {
                    $object = $pool->fetchObj();
                    self::assertIsObject($object);
                    $resourceIds[] = spl_object_id($object->getObject());
                    $ready->push(true);
                    self::assertTrue((bool) $release->pop(5.0));
                    $pool->pushObj($object);
                    $completed->push(true);
                });
            }

            for ($i = 0; $i < $capacity; ++$i) {
                self::assertTrue((bool) $ready->pop(5.0));
            }
            self::assertSame($capacity, $this->getProtectedInt($pool, 'callCount'));
            self::assertSame(0, $pool->getCurrentNum());
            self::assertCount($capacity, array_unique($resourceIds));

            for ($i = 0; $i < $capacity; ++$i) {
                $release->push(true);
            }
            $this->awaitCompleted($completed, $capacity);
            $this->assertRecovered($pool, $capacity);
            self::assertSame(0, $this->getProtectedRegistryCount($pool, 'borrowedHandles'));
            self::assertSame($capacity, $this->getProtectedInt($pool, 'objectCount'));
        });
    }

    /**
     * 多个非 owner 协程同时归还同一个连接必须全部失败，且连接不能提前被再次借出。
     */
    public function testConcurrentReturnOfSameObjectByWrongCoroutinesCannotStealOwnership(): void
    {
        PoolHandlerTestResource::reset();
        $this->runInCoroutine(function (): void {
            $borrowed = null;
            $ownerReady = new Channel(1);
            $ownerRelease = new Channel(1);
            $ownerCompleted = new Channel(1);
            $attackCompleted = new Channel(2);
            $pool = $this->createPool(1, static fn (): PoolHandlerTestResource => new PoolHandlerTestResource(), 0.01);

            Coroutine::create(static function () use (
                $pool,
                &$borrowed,
                $ownerReady,
                $ownerRelease,
                $ownerCompleted,
            ): void {
                $borrowed = $pool->fetchObj();
                self::assertIsObject($borrowed);
                $ownerReady->push(true);
                self::assertTrue((bool) $ownerRelease->pop(5.0));
                $pool->pushObj($borrowed);
                $ownerCompleted->push(true);
            });
            self::assertTrue((bool) $ownerReady->pop(5.0));

            for ($i = 0; $i < 2; ++$i) {
                Coroutine::create(static function () use ($pool, &$borrowed, $attackCompleted): void {
                    $pool->pushObj($borrowed);
                    $attackCompleted->push(true);
                });
            }
            $this->awaitCompleted($attackCompleted, 2);

            self::assertSame(1, $this->getProtectedInt($pool, 'callCount'));
            self::assertSame(1, $this->getProtectedRegistryCount($pool, 'borrowedHandles'));
            self::assertSame(0, $pool->getCurrentNum());
            self::assertIsObject($borrowed->getObject());
            // owner 尚未释放时没有空闲资源，错误归还不能让另一协程复用该连接。
            self::assertNull($pool->fetchObj());

            $ownerRelease->push(true);
            self::assertTrue((bool) $ownerCompleted->pop(5.0));
            $this->assertRecovered($pool, 1);
            self::assertSame(0, $this->getProtectedRegistryCount($pool, 'borrowedHandles'));
        });
    }

    /**
     * 同一 owner 重复归还旧句柄时，第二次调用必须幂等且不能命中下一轮 lease。
     */
    public function testDuplicateReturnIsIdempotentAndOldHandleIsRevoked(): void
    {
        PoolHandlerTestResource::reset();
        $this->runInCoroutine(function (): void {
            $pool = $this->createPool(1, static fn (): PoolHandlerTestResource => new PoolHandlerTestResource());
            $firstHandle = $pool->fetchObj();
            self::assertIsObject($firstHandle);
            $resourceId = spl_object_id($firstHandle->getObject());

            $pool->pushObj($firstHandle);
            $pool->pushObj($firstHandle);
            self::assertNull($firstHandle->getObject());
            self::assertSame(0, $this->getProtectedInt($pool, 'callCount'));
            self::assertSame(0, $this->getProtectedRegistryCount($pool, 'borrowedHandles'));
            $this->assertRecovered($pool, 1);

            $secondHandle = $pool->fetchObj();
            self::assertIsObject($secondHandle);
            self::assertNotSame($firstHandle, $secondHandle);
            self::assertSame($resourceId, spl_object_id($secondHandle->getObject()));

            // 旧句柄在新 lease 建立后再次归还，也不能删除或结算新 owner 的记录。
            $pool->pushObj($firstHandle);
            self::assertSame(1, $this->getProtectedInt($pool, 'callCount'));
            self::assertSame(1, $this->getProtectedRegistryCount($pool, 'borrowedHandles'));
            $pool->pushObj($secondHandle);
            $this->assertRecovered($pool, 1);
        });
    }

    /**
     * Channel 关闭导致发布失败时，归还与容量账本必须收敛且不得出现负数。
     */
    public function testClosedChannelReturnDiscardsLeaseWithoutCapacityLeak(): void
    {
        PoolHandlerTestResource::reset();
        $this->runInCoroutine(function (): void {
            $pool = $this->createPool(1, static fn (): PoolHandlerTestResource => new PoolHandlerTestResource());
            $object = $pool->fetchObj();
            self::assertIsObject($object);
            $pool->getChannel()->close();

            $pool->pushObj($object);
            $deadline = microtime(true) + 5.0;
            while (
                $this->getProtectedInt($pool, 'callCount') !== 0
                || $this->getProtectedInt($pool, 'objectCount') !== 0
                || $this->getProtectedRegistryCount($pool, 'borrowedHandles') !== 0
            ) {
                if (microtime(true) >= $deadline) {
                    self::fail('Channel 关闭后的归还状态未收敛。');
                }
                Coroutine::sleep(0.001);
            }

            self::assertSame(0, $this->getProtectedInt($pool, 'callCount'));
            self::assertSame(0, $this->getProtectedInt($pool, 'objectCount'));
            self::assertSame(0, $pool->getCurrentNum());
            self::assertNull($object->getObject());
        });
    }

    /**
     * Channel 已满时强制触发 push timeout；丢弃、refill 失败和后续恢复均须守恒。
     */
    public function testPushTimeoutReleasesExactlyOneSlotAndPoolCanRecover(): void
    {
        PoolHandlerTestResource::reset();
        $this->runInCoroutine(function (): void {
            $pool = $this->createPool(1, static fn (): PoolHandlerTestResource => new PoolHandlerTestResource());
            $pool->setPushTimeout(0.001);
            $object = $pool->fetchObj();
            self::assertIsObject($object);

            // 直接填满公开 Channel，仅用于确定性制造归还 push timeout。
            $foreignMarker = new \stdClass();
            self::assertTrue($pool->getChannel()->push($foreignMarker, 0.01));
            $pool->pushObj($object);

            $deadline = microtime(true) + 5.0;
            while (
                $this->getProtectedInt($pool, 'callCount') !== 0
                || $this->getProtectedInt($pool, 'objectCount') !== 0
                || $this->getProtectedRegistryCount($pool, 'borrowedHandles') !== 0
            ) {
                if (microtime(true) >= $deadline) {
                    self::fail('push timeout 后容量账本未收敛。');
                }
                Coroutine::sleep(0.001);
            }

            self::assertSame(1, $pool->getCurrentNum());
            self::assertSame($foreignMarker, $pool->getChannel()->pop(0.01));
            self::assertSame(0, $pool->getCurrentNum());
            self::assertGreaterThanOrEqual(0, $this->getProtectedInt($pool, 'callCount'));

            $replacement = $pool->fetchObj();
            self::assertIsObject($replacement);
            $pool->pushObj($replacement);
            $this->assertRecovered($pool, 1);
            self::assertSame(1, $this->getProtectedInt($pool, 'objectCount'));
        });
    }

    /**
     * 业务遗失最后一个借用句柄时，Pool 不能以强引用阻止句柄和底层资源被 GC；
     * 下一次 fetch 应根据 WeakMap 存活数回收旧槽位并正常补建。
     */
    public function testAbandonedHandleCanBeCollectedAndCapacityRecoversOnNextFetch(): void
    {
        PoolHandlerTestResource::reset();
        $this->runInCoroutine(function (): void {
            $pool = $this->createPool(1, static fn (): PoolHandlerTestResource => new PoolHandlerTestResource());
            self::assertInstanceOf(\WeakMap::class, $this->getProtectedValue($pool, 'borrowedHandles'));

            for ($round = 0; $round < 50; ++$round) {
                $abandoned = $pool->fetchObj();
                self::assertIsObject($abandoned);
                self::assertSame(1, $this->getProtectedRegistryCount($pool, 'borrowedHandles'));

                $weakHandle = \WeakReference::create($abandoned);
                unset($abandoned);
                gc_collect_cycles();

                self::assertNull($weakHandle->get(), 'PoolsHandler 不应强引用已遗失的借用句柄。');
                self::assertSame(0, $this->getProtectedRegistryCount($pool, 'borrowedHandles'));
                self::assertSame(0, PoolHandlerTestResource::$alive);
            }

            // fetch 开始时收敛 callCount/objectCount，再按容量 reservation 创建替代资源。
            $replacement = $pool->fetchObj();
            self::assertIsObject($replacement);
            self::assertSame(1, $this->getProtectedInt($pool, 'callCount'));
            self::assertSame(1, $this->getProtectedInt($pool, 'objectCount'));
            self::assertSame(1, $this->getProtectedRegistryCount($pool, 'borrowedHandles'));
            self::assertSame(51, PoolHandlerTestResource::$created);
            self::assertSame(1, PoolHandlerTestResource::$alive);
            self::assertSame(1, PoolHandlerTestResource::$peakAlive);

            $pool->pushObj($replacement);
            $this->assertRecovered($pool, 1);
            self::assertSame(0, $this->getProtectedRegistryCount($pool, 'borrowedHandles'));
        });
    }

    /**
     * 创建未注册到全局 CoroutinePools 的独立测试池。
     *
     * @param callable(): object $constructor
     */
    private function createPool(int $capacity, callable $constructor, float $popTimeout = 1.0): PoolsHandler
    {
        $pool = new PoolsHandler();
        $pool->setPoolsNum($capacity);
        $pool->setPushTimeout(0.1);
        $pool->setPopTimeout($popTimeout);
        $pool->setLifeTime(3600);
        $pool->setBuildCallable($constructor);
        $pool->registerPools('pools-handler-concurrency-test');

        return $pool;
    }

    /**
     * 使用 Channel 完成信号等待业务协程，避免以固定睡眠猜测调度进度。
     */
    private function awaitCompleted(Channel $completed, int $expected): void
    {
        for ($i = 0; $i < $expected; ++$i) {
            self::assertTrue((bool) $completed->pop(10.0), sprintf('第 %d 个协程未在期限内完成。', $i + 1));
        }
    }

    /**
     * 等待 pushObj/refill 的异步归还路径收敛，并验证可观察池状态。
     */
    private function assertRecovered(PoolsHandler $pool, int $capacity): void
    {
        $deadline = microtime(true) + 5.0;
        while (
            $this->getProtectedInt($pool, 'callCount') !== 0
            || $pool->getCurrentNum() !== $capacity
        ) {
            if (microtime(true) >= $deadline) {
                self::fail(sprintf(
                    '对象池未恢复：callCount=%d, objectCount=%d, channel=%d。',
                    $this->getProtectedInt($pool, 'callCount'),
                    $this->getProtectedInt($pool, 'objectCount'),
                    $pool->getCurrentNum(),
                ));
            }
            Coroutine::sleep(0.001);
        }

        self::assertGreaterThanOrEqual(0, $this->getProtectedInt($pool, 'callCount'));
        self::assertLessThanOrEqual($capacity, $pool->getCurrentNum());
    }

    /**
     * 生产 API 不暴露内部计数，测试通过反射验证并发不变量。
     */
    private function getProtectedInt(PoolsHandler $pool, string $property): int
    {
        $reflection = new ReflectionProperty($pool, $property);
        $reflection->setAccessible(true);

        return (int) $reflection->getValue($pool);
    }

    /**
     * 读取内部弱租约集合数量，验证 lease 最终没有泄漏或被错误删除。
     */
    private function getProtectedRegistryCount(PoolsHandler $pool, string $property): int
    {
        $value = $this->getProtectedValue($pool, $property);

        return is_countable($value) ? count($value) : 0;
    }

    /**
     * 获取测试所需的 protected 内部值，不为生产代码增加观测 API。
     *
     * @return mixed
     */
    private function getProtectedValue(PoolsHandler $pool, string $property)
    {
        $reflection = new ReflectionProperty($pool, $property);
        $reflection->setAccessible(true);

        return $reflection->getValue($pool);
    }
}

/**
 * 仅用于容量测试的轻量资源，实时记录构造和存活峰值。
 */
final class PoolHandlerTestResource
{
    public static int $created = 0;

    public static int $alive = 0;

    public static int $peakAlive = 0;

    public function __construct()
    {
        ++self::$created;
        ++self::$alive;
        self::$peakAlive = max(self::$peakAlive, self::$alive);
    }

    public function __destruct()
    {
        --self::$alive;
    }

    /**
     * 在每个独立场景前清空资源统计，禁止跨用例污染断言。
     */
    public static function reset(): void
    {
        self::$created = 0;
        self::$alive = 0;
        self::$peakAlive = 0;
    }
}
