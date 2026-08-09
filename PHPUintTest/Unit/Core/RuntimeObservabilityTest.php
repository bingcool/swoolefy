<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Core;

use PHPUintTest\TestCase;
use Swoolefy\Core\Runtime\Memory\MemoryHistory;
use Swoolefy\Core\Runtime\Memory\MemoryLeakDetector;
use Swoolefy\Core\Runtime\Memory\MemorySnapshot;
use Swoolefy\Core\Runtime\Metrics\Counter;
use Swoolefy\Core\Runtime\Metrics\Gauge;
use Swoolefy\Core\Runtime\Metrics\Histogram;
use Swoolefy\Core\Runtime\Metrics\MetricsRegistry;
use Swoolefy\Core\Runtime\Metrics\RuntimeMetrics;
use Swoolefy\Core\Runtime\RuntimeRegistry;

/**
 * 对容量有界的 Worker 本地运行时可观测性基础组件进行回归测试。
 */
final class RuntimeObservabilityTest extends TestCase
{
    protected function tearDown(): void
    {
        RuntimeRegistry::reset();
    }

    /** Counter 单调递增，并拒绝非法的负增量。 */
    public function testCounter(): void
    {
        $counter = new Counter();
        $counter->increment();
        $counter->increment(2);
        self::assertSame(3, $counter->value());
        $this->expectException(\InvalidArgumentException::class);
        $counter->increment(-1);
    }

    /** Gauge 和 Histogram 仅保留容量有界的标量状态。 */
    public function testGaugeAndHistogramSnapshot(): void
    {
        $gauge = new Gauge();
        $gauge->increment(10);
        $gauge->decrement(3);
        self::assertSame(7, $gauge->value());
        $histogram = new Histogram();
        $histogram->observe(0.1);
        $histogram->observe(0.3);
        self::assertSame(['count' => 2, 'sum' => 0.4, 'min' => 0.1, 'max' => 0.3, 'avg' => 0.2], $histogram->snapshot());
    }

    /** 请求和连接池 API 仅使用固定的汇总指标名。 */
    public function testRuntimeMetricsLifecycle(): void
    {
        $metrics = new RuntimeMetrics(new MetricsRegistry());
        $metrics->requestStarted();
        $metrics->requestError();
        $metrics->requestDuration(0.25);
        $metrics->requestFinished();
        $metrics->poolFetched('untrusted-name');
        $metrics->poolReleased('another-name');
        $snapshot = $metrics->snapshot();
        self::assertSame(1, $snapshot['counter'][RuntimeMetrics::HTTP_REQUESTS_TOTAL]);
        self::assertSame(0, $snapshot['gauge'][RuntimeMetrics::HTTP_REQUESTS_ACTIVE]);
        self::assertSame(1, $snapshot['counter'][RuntimeMetrics::HTTP_5XX_TOTAL]);
        self::assertSame(1, $snapshot['counter'][RuntimeMetrics::POOL_FETCH_TOTAL]);
        self::assertSame(1, $snapshot['counter'][RuntimeMetrics::POOL_RELEASE_TOTAL]);
        self::assertArrayNotHasKey('untrusted-name', $snapshot['counter']);
        $metrics->poolFetchError('failed-pool');
        self::assertSame(1, $metrics->snapshot()['counter'][RuntimeMetrics::POOL_FETCH_ERROR_TOTAL]);
    }

    /** 历史记录会淘汰旧观测值，因此不会无限增长。 */
    public function testMemoryHistoryIsBounded(): void
    {
        $history = new MemoryHistory(10);
        for ($i = 0; $i < 20; ++$i) {
            $history->push($this->sample($i, 100 + $i));
        }
        self::assertCount(10, $history->all());
        self::assertSame(10, $history->all()[0]->timestamp);
    }

    /** 检测器区分早期数据、波动、持续增长和临界阈值。 */
    public function testMemoryLeakStateMachine(): void
    {
        $detector = new MemoryLeakDetector(3, 20, 0, 0, 0.7, 5);
        self::assertSame(MemoryLeakDetector::NORMAL, $detector->detect([$this->sample(1, 100)])->state);
        $oscillating = [100, 130, 105, 132, 110];
        self::assertSame(MemoryLeakDetector::OBSERVING, $detector->detect($this->samples($oscillating))->state);
        self::assertSame(MemoryLeakDetector::SUSPECTED, $detector->detect($this->samples([100, 110, 120, 130, 140]))->state);
        self::assertSame(MemoryLeakDetector::NORMAL, $detector->detect($this->samples([100, 110, 120, 90, 80]))->state);
        $rssStable = array_map(
            static fn (int $memory, int $index): MemorySnapshot => new MemorySnapshot($index, $index, $memory, $memory, $memory, 100, 1),
            [100, 110, 120, 130, 140],
            array_keys([100, 110, 120, 130, 140]),
        );
        self::assertSame(MemoryLeakDetector::OBSERVING, $detector->detect($rssStable)->state);
        $critical = new MemoryLeakDetector(3, 20, 150, 0, 0.7, 5);
        self::assertSame(MemoryLeakDetector::CRITICAL, $critical->detect($this->samples([100, 110, 120, 130, 150]))->state);
    }

    /** 省略 diagnostics.enable 时仍提供低开销的按需诊断。 */
    public function testRegistryRespectsDisabledMetrics(): void
    {
        RuntimeRegistry::initialize([
            'metrics' => ['enable' => false],
            'memory' => ['enable' => true, 'sample_interval' => 60000],
        ]);
        self::assertNull(RuntimeRegistry::metrics());
        self::assertNotNull(RuntimeRegistry::memory());
        self::assertNotNull(RuntimeRegistry::diagnostics());
        $snapshot = RuntimeRegistry::diagnostics()?->snapshot();
        self::assertSame(['enabled' => false], $snapshot['metrics']);
        self::assertArrayHasKey('php_usage', $snapshot['memory']);
        self::assertArrayHasKey('rss', $snapshot['memory']);
    }

    /** 显式关闭诊断时不创建诊断组件。 */
    public function testRegistryRespectsDisabledDiagnostics(): void
    {
        RuntimeRegistry::initialize([
            'diagnostics' => ['enable' => false],
        ]);

        self::assertNull(RuntimeRegistry::diagnostics());
    }

    /** 服务端采集器不可用时，诊断结果仍部分可用。 */
    public function testDiagnosticsAreCollectorIsolated(): void
    {
        RuntimeRegistry::initialize(['memory' => ['enable' => false]]);
        $snapshot = RuntimeRegistry::diagnostics()?->snapshot();
        self::assertIsArray($snapshot);
        self::assertArrayHasKey('runtime', $snapshot);
        self::assertArrayHasKey('memory', $snapshot);
        self::assertSame(['enabled' => false], $snapshot['memory']);
        self::assertSame('collector_failed', $snapshot['server']['error']);
        self::assertSame(0, $snapshot['pool']['balance']);
    }

    /** 创建确定性的模拟内存数据，避免实际分配内存。 */
    private function sample(int $time, int $memory): MemorySnapshot
    {
        return new MemorySnapshot($time, $time * 10, $memory, $memory, $memory, $memory, $time);
    }

    /** @param list<int> $values @return list<MemorySnapshot> */
    private function samples(array $values): array
    {
        return array_map(fn (int $memory, int $index): MemorySnapshot => $this->sample($index, $memory), $values, array_keys($values));
    }
}
