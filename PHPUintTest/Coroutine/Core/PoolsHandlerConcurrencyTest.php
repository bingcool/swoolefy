<?php

declare(strict_types=1);

namespace PHPUintTest\Coroutine\Core;

use PHPUintTest\CoroutineTestCase;
use RuntimeException;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoolefy\Core\Coroutine\PoolsHandler;
use Throwable;

/**
 * PoolsHandler 容量并发回归。
 *
 * 所有用例直接操作 PoolsHandler，避免 ComponentTrait 在 fetch 失败时创建新对象而掩盖问题。
 */
final class PoolsHandlerConcurrencyTest extends CoroutineTestCase
{
    public function testYieldingConstructorNeverCreatesBeyondCapacity(): void
    {
        $this->runInCoroutine(function (): void {
            $capacity = 5;
            $coroutines = 1000;
            TestPoolResource::reset($capacity);
            $pool = $this->createPool($capacity, static function (): TestPoolResource {
                // 强制在「预占 -> 构造」之间切换协程，稳定放大旧 check -> yield -> make 竞态。
                Coroutine::sleep(0.001);
                return new TestPoolResource();
            });

            $completed = 0;
            $finished = 0;
            $errors = new Channel($coroutines);
            for ($i = 0; $i < $coroutines; ++$i) {
                go(function () use ($pool, &$completed, &$finished, $errors): void {
                    try {
                        $object = $pool->fetchObj();
                        if (!is_object($object)) {
                            throw new RuntimeException('Pool fetch returned null.');
                        }
                        Coroutine::sleep(0.001);
                        $pool->pushObj($object);
                        ++$completed;
                    } catch (Throwable $exception) {
                        $errors->push($exception);
                    } finally {
                        ++$finished;
                    }
                });
            }

            $deadline = microtime(true) + 15;
            while ($finished !== $coroutines && microtime(true) < $deadline) {
                Coroutine::sleep(0.005);
            }
            self::assertSame(
                $coroutines,
                $finished,
                sprintf(
                    '1000 个协程未在时限内完成：finished=%d, completed=%d, errors=%d, callCount=%d, channel=%d, objectCount=%d',
                    $finished,
                    $completed,
                    $errors->length(),
                    $this->readCounter($pool, 'callCount'),
                    $pool->getCurrentNum(),
                    $this->readCounter($pool, 'objectCount'),
                ),
            );
            $this->assertSame(
                $coroutines,
                $completed,
                sprintf('并发 fetch/release 出现 %d 个失败', $errors->length()),
            );
            $this->waitForPoolRecovery($pool, $capacity);

            $this->assertFalse(TestPoolResource::$overflowDetected, '构造瞬间不允许突破容量');
            $this->assertLessThanOrEqual($capacity, TestPoolResource::$created);
            $this->assertLessThanOrEqual($capacity, TestPoolResource::$peakActive);
            $this->assertSame($capacity, TestPoolResource::$active);
            $this->assertSame($capacity, $this->readCounter($pool, 'objectCount'));
        });
    }

    public function testFailedCreationRollsBackReservation(): void
    {
        $this->runInCoroutine(function (): void {
            $pool = $this->createPool(1, static function (): object {
                Coroutine::sleep(0.001);
                throw new RuntimeException('expected constructor failure');
            });
            $make = new \ReflectionMethod($pool, 'make');
            $make->setAccessible(true);

            try {
                $make->invoke($pool, 1);
                self::fail('创建回调异常必须继续向上抛出');
            } catch (RuntimeException $exception) {
                self::assertSame('expected constructor failure', $exception->getMessage());
            }

            $this->assertSame(0, $this->readCounter($pool, 'objectCount'));
            $this->assertSame(0, $pool->getCurrentNum());
        });
    }

    public function testCapacityOneAndTwoRemainBoundedDuringYieldingFetchRelease(): void
    {
        $this->runInCoroutine(function (): void {
            foreach ([1 => 100, 2 => 500] as $capacity => $coroutines) {
                TestPoolResource::reset($capacity);
                $pool = $this->createPool($capacity, static function (): TestPoolResource {
                    Coroutine::sleep(0.001);
                    return new TestPoolResource();
                });
                $completed = 0;

                for ($i = 0; $i < $coroutines; ++$i) {
                    go(function () use ($pool, &$completed): void {
                        $object = $pool->fetchObj();
                        if (!is_object($object)) {
                            throw new RuntimeException('Pool starvation.');
                        }
                        Coroutine::sleep(0.001);
                        $pool->pushObj($object);
                        ++$completed;
                    });
                }

                $this->waitFor(
                    static fn (): bool => $completed === $coroutines,
                    sprintf('capacity=%d 的协程池发生永久等待', $capacity),
                );
                $this->waitForPoolRecovery($pool, $capacity);
                self::assertFalse(TestPoolResource::$overflowDetected);
                self::assertLessThanOrEqual($capacity, TestPoolResource::$peakActive);
                self::assertSame($capacity, $this->readCounter($pool, 'objectCount'));
            }
        });
    }

    public function testExpiredAndDuplicateReturnKeepAccountingConsistent(): void
    {
        $this->runInCoroutine(function (): void {
            TestPoolResource::reset(1);
            $pool = $this->createPool(1, static function (): TestPoolResource {
                return new TestPoolResource();
            });

            $object = $pool->fetchObj();
            self::assertIsObject($object);

            // 第一次归还已撤销借出标记；同协程的重复归还必须被拒绝。
            $pool->pushObj($object);
            $pool->pushObj($object);
            $this->waitForPoolRecovery($pool, 1);
            self::assertSame(1, $this->readCounter($pool, 'objectCount'));

            $expired = $pool->fetchObj();
            self::assertIsObject($expired);
            $expired->__objExpireTime = time() - 1;
            $pool->pushObj($expired);
            unset($expired);
            $this->waitForPoolRecovery($pool, 1);

            // 过期对象的调用方变量在当前作用域仍可能保留旧引用，故此场景只校验账本；
            // peakActive 的容量断言仅适用于不丢弃对象的标准场景。
            self::assertGreaterThanOrEqual(2, TestPoolResource::$created);
            self::assertSame(1, $this->readCounter($pool, 'objectCount'));
            self::assertSame(1, $pool->getCurrentNum());

            // clearPool 只释放空闲对象，账本必须同步归零，下一次 fetch 可正常重建。
            $pool->clearPool();
            self::assertSame(0, $pool->getCurrentNum());
            self::assertSame(0, $this->readCounter($pool, 'objectCount'));
            $rebuilt = $pool->fetchObj();
            self::assertIsObject($rebuilt);
            $pool->pushObj($rebuilt);
            unset($rebuilt);
            $this->waitForPoolRecovery($pool, 1);
        });
    }

    /**
     * @param callable(): object $constructor
     */
    private function createPool(int $capacity, callable $constructor): PoolsHandler
    {
        $pool = new PoolsHandler();
        $pool->setPoolsNum($capacity);
        $pool->setLifeTime(3600);
        $pool->setPopTimeout(5.0);
        $pool->setPushTimeout(5.0);
        $pool->setBuildCallable($constructor);
        $pool->registerPools('pools-handler-concurrency-test');
        return $pool;
    }

    private function waitForPoolRecovery(PoolsHandler $pool, int $capacity): void
    {
        $this->waitFor(
            fn (): bool => $this->readCounter($pool, 'callCount') === 0
                && $pool->getCurrentNum() === $capacity,
            sprintf('对象池未恢复：callCount=%d, channel=%d', $this->readCounter($pool, 'callCount'), $pool->getCurrentNum()),
        );
    }

    /**
     * @param callable(): bool $condition
     */
    private function waitFor(callable $condition, string $message): void
    {
        $deadline = microtime(true) + 15;
        while (!$condition()) {
            if (microtime(true) >= $deadline) {
                self::fail($message);
            }
            Coroutine::sleep(0.005);
        }
    }

    private function readCounter(PoolsHandler $pool, string $property): int
    {
        $reflection = new \ReflectionProperty($pool, $property);
        $reflection->setAccessible(true);
        return (int) $reflection->getValue($pool);
    }
}

/**
 * 仅用于并发测试的轻量资源，实时记录创建和存活峰值。
 */
final class TestPoolResource
{
    public static int $created = 0;
    public static int $destroyed = 0;
    public static int $active = 0;
    public static int $peakActive = 0;
    public static bool $overflowDetected = false;
    private static int $capacity = 0;

    public function __construct()
    {
        ++self::$created;
        ++self::$active;
        self::$peakActive = max(self::$peakActive, self::$active);
        if (self::$created > self::$capacity) {
            self::$overflowDetected = true;
        }
    }

    public function __destruct()
    {
        ++self::$destroyed;
        --self::$active;
    }

    public static function reset(int $capacity): void
    {
        self::$created = 0;
        self::$destroyed = 0;
        self::$active = 0;
        self::$peakActive = 0;
        self::$overflowDetected = false;
        self::$capacity = $capacity;
    }
}
