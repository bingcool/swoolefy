<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Core;

use PHPUintTest\TestCase;
use Swoolefy\Core\Runtime\Memory\MemoryHistory;
use Swoolefy\Core\Runtime\Memory\MemoryLeakDetector;
use Swoolefy\Core\Runtime\Memory\MemorySnapshot;
use Swoolefy\Core\Runtime\Diagnostics\RuntimeDiagnostics;
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

    /**
     * 非池指标保持固定名称；池指标按启动时白名单中的组件别名聚合。
     *
     * 同一别名的成功、归还和失败必须累计在同一快照项；未知别名只能增加固定的
     * 未归属计数器，不能在 Worker 常驻内存中创建动态别名键。
     */
    public function testRuntimeMetricsLifecycle(): void
    {
        $metrics = new RuntimeMetrics(new MetricsRegistry(), ['redis', 'mysql']);
        $metrics->requestStarted();
        $metrics->requestError();
        $metrics->requestDuration(0.25);
        $metrics->requestFinished();
        $metrics->poolFetched('redis');
        $metrics->poolFetched('redis');
        $metrics->poolReleased('redis');
        $metrics->poolFetchError('redis');
        $metrics->poolFetched('mysql');
        $metrics->poolReleased('mysql');
        $metrics->poolFetched('untrusted-name');
        $snapshot = $metrics->snapshot();
        self::assertSame(1, $snapshot['counter'][RuntimeMetrics::HTTP_REQUESTS_TOTAL]);
        self::assertSame(0, $snapshot['gauge'][RuntimeMetrics::HTTP_REQUESTS_ACTIVE]);
        self::assertSame(1, $snapshot['counter'][RuntimeMetrics::HTTP_5XX_TOTAL]);
        self::assertSame(4, $snapshot['counter'][RuntimeMetrics::POOL_FETCH_TOTAL]);
        self::assertSame(2, $snapshot['counter'][RuntimeMetrics::POOL_RELEASE_TOTAL]);
        self::assertArrayNotHasKey('untrusted-name', $snapshot['counter']);
        self::assertSame(1, $snapshot['counter'][RuntimeMetrics::POOL_FETCH_ERROR_TOTAL]);
        self::assertSame(1, $snapshot['counter'][RuntimeMetrics::POOL_UNATTRIBUTED_TOTAL]);
        self::assertSame([
            'redis' => [
                'fetch_total' => 2,
                'release_total' => 1,
                'fetch_error_total' => 1,
                'balance' => 1,
            ],
            'mysql' => [
                'fetch_total' => 1,
                'release_total' => 1,
                'fetch_error_total' => 0,
                'balance' => 0,
            ],
        ], $metrics->poolSnapshot());
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

    /** 指标关闭时，Worker 本地指标明确标记为禁用，不伪造服务级指标。 */
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
        self::assertSame(['enabled' => false], $snapshot['worker']['metrics']);
        self::assertSame('collector_failed', $snapshot['global']['server']['error']);
        self::assertArrayHasKey('php_usage', $snapshot['worker']['memory']);
        self::assertArrayHasKey('rss', $snapshot['worker']['memory']);
    }

    /** 组件池别名只能位于当前 Worker 的 pool.aliases 中。 */
    public function testDiagnosticsExposePerAliasPoolSnapshot(): void
    {
        RuntimeRegistry::initialize([
            'metrics' => ['enable' => true],
            'memory' => ['enable' => false],
            'pool_aliases' => ['redis', 'db'],
        ]);

        $metrics = RuntimeRegistry::metrics();
        self::assertNotNull($metrics);
        $metrics->poolFetched('redis');
        $metrics->poolFetched('redis');
        $metrics->poolReleased('redis');
        $metrics->poolFetchError('db');

        $snapshot = RuntimeRegistry::diagnostics()?->snapshot();
        $pool = $snapshot['worker']['pool']['aliases'];
        self::assertSame([
            'redis' => [
                'fetch_total' => 2,
                'release_total' => 1,
                'fetch_error_total' => 0,
                'balance' => 1,
            ],
            'db' => [
                'fetch_total' => 0,
                'release_total' => 0,
                'fetch_error_total' => 1,
                'balance' => 0,
            ],
        ], $pool);
        self::assertArrayNotHasKey('pool', $snapshot);
        self::assertArrayNotHasKey('aliases', $snapshot['worker']['metrics']['pool']);
    }

    /**
     * 响应顶层严格只有 global 与 worker。Swoole Server stats 是唯一的服务级来源；
     * RuntimeRegistry 的 PHP 静态状态只能描述当前 Worker，不能伪造全局框架指标。
     * HTTP Worker 未 registerCronSnapshot 时 worker.cron.enabled=false，
     * 但 worker.metrics.cron 键仍存在（Gauge 为 0）。
     */
    public function testDiagnosticsClassifySourcesIntoStrictGlobalAndWorkerGroups(): void
    {
        RuntimeRegistry::initialize([
            'metrics' => ['enable' => true],
            'memory' => ['enable' => false],
            'pool_aliases' => ['redis'],
        ]);
        $metrics = RuntimeRegistry::metrics();
        self::assertNotNull($metrics);
        $metrics->requestStarted();
        $metrics->poolFetched('redis');

        $diagnostics = new RuntimeDiagnostics(
            static fn (): array => ['request_count' => 99, 'worker_num' => 2],
        );
        $snapshot = $diagnostics->snapshot();

        self::assertSame([
            'request_count' => 99,
            'worker_num' => 2,
        ], $snapshot['global']['server']);
        self::assertSame(['global', 'worker'], array_keys($snapshot));
        self::assertSame(['enabled' => false], $snapshot['worker']['cron']);
        self::assertArrayHasKey('cron', $snapshot['worker']['metrics']);
        self::assertSame(1, $snapshot['worker']['metrics']['request']['counter'][RuntimeMetrics::HTTP_REQUESTS_TOTAL]);
        self::assertSame(1, $snapshot['worker']['pool']['aliases']['redis']['fetch_total']);
        self::assertArrayHasKey('pid', $snapshot['worker']['process']);
        self::assertArrayHasKey('system', $snapshot['global']);
        self::assertArrayNotHasKey('parent_pid', $snapshot['global']['process']);
        self::assertArrayNotHasKey('metrics', $snapshot['global']);
        self::assertArrayNotHasKey('pool', $snapshot['global']);
        foreach (['runtime', 'process', 'server', 'coroutine', 'memory', 'metrics', 'pool', 'platform', 'service'] as $oldKey) {
            self::assertArrayNotHasKey($oldKey, $snapshot);
        }
    }

    /** 显式关闭诊断时不创建诊断组件。 */
    public function testRegistryRespectsDisabledDiagnostics(): void
    {
        RuntimeRegistry::initialize([
            'diagnostics' => ['enable' => false],
        ]);

        self::assertNull(RuntimeRegistry::diagnostics());
    }

    /** 服务端采集器不可用时，其他全局与当前 Worker 分区仍可用。 */
    public function testDiagnosticsAreCollectorIsolated(): void
    {
        RuntimeRegistry::initialize(['memory' => ['enable' => false]]);
        $snapshot = RuntimeRegistry::diagnostics()?->snapshot();
        self::assertIsArray($snapshot);
        self::assertSame(['global', 'worker'], array_keys($snapshot));
        self::assertArrayHasKey('system', $snapshot['global']);
        self::assertSame(['enabled' => false], $snapshot['worker']['memory']);
        self::assertSame('collector_failed', $snapshot['global']['server']['error']);
        self::assertSame(['enabled' => false], $snapshot['worker']['metrics']);
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
