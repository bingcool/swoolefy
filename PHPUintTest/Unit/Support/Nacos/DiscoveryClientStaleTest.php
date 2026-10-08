<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Support\Nacos;

use PHPUintTest\TestCase;
use RuntimeException;
use Swoolefy\Support\Nacos\Discovery\Contract\DiscoveryDriverInterface;
use Swoolefy\Support\Nacos\Discovery\DiscoveryClient;
use Swoolefy\Support\Nacos\Discovery\DiscoveryConfig;
use Swoolefy\Support\Nacos\Discovery\Model\ServiceInstance;
use Throwable;

/**
 * 成功空列表立即生效；失败在 staleTtl 内保留旧实例，超时清空。
 */
final class DiscoveryClientStaleTest extends TestCase
{
    public function testSuccessfulEmptyListDropsCachedInstances(): void
    {
        $instance = $this->instanceA();
        $driver = new ScriptedDiscoveryDriver([[$instance], [], []]);
        $client = $this->client($driver, cacheTtl: 60, staleTtl: 180);

        $this->assertSame('10.0.0.1', $client->getInstances()[0]->ip);
        $client->getInstances(true);

        $this->assertSame([], $client->getInstances(false));
        $this->assertNull($client->choose());
    }

    public function testFailureWithinStaleTtlKeepsInstancesAndSkipsDriverInsideCacheWindow(): void
    {
        $driver = new ScriptedDiscoveryDriver([[$this->instanceA()], new RuntimeException('down')]);
        $clock = new MutableClock(1_000_000);
        $client = $this->client($driver, cacheTtl: 60, staleTtl: 180, clock: $clock);

        $client->getInstances();
        $clock->now = 1_000_060;
        $stale = $client->getInstances(false);

        $this->assertSame('10.0.0.1', $stale[0]->ip);
        $this->assertSame(2, $driver->calls);

        $again = $client->getInstances(false);
        $this->assertSame('10.0.0.1', $again[0]->ip);
        $this->assertSame(2, $driver->calls);
    }

    public function testFailurePastStaleTtlClearsInstances(): void
    {
        $driver = new ScriptedDiscoveryDriver([
            [$this->instanceA()],
            new RuntimeException('down'),
            new RuntimeException('still down'),
        ]);
        $clock = new MutableClock(1_000_000);
        $client = $this->client($driver, cacheTtl: 60, staleTtl: 180, clock: $clock);

        $client->getInstances();
        $clock->now = 1_000_060;
        $client->getInstances(false);
        $clock->now = 1_000_181;
        $this->assertSame([], $client->getInstances(false));
        $this->assertNull($client->choose());
    }

    public function testZeroStaleTtlClearsOnFirstFailure(): void
    {
        $driver = new ScriptedDiscoveryDriver([[$this->instanceA()], new RuntimeException('down')]);
        $clock = new MutableClock(1_000_000);
        $client = $this->client($driver, cacheTtl: 60, staleTtl: 0, clock: $clock);

        $client->getInstances();
        $clock->now = 1_000_060;

        $this->assertSame([], $client->getInstances(false));
    }

    public function testFailureWithoutCacheReturnsEmpty(): void
    {
        $driver = new ScriptedDiscoveryDriver([new RuntimeException('down')]);
        $client = $this->client($driver, cacheTtl: 60, staleTtl: 180);

        $this->assertSame([], $client->getInstances());
        $this->assertSame(1, $driver->calls);
    }

    private function instanceA(): ServiceInstance
    {
        return new ServiceInstance('demo', '10.0.0.1', 80);
    }

    private function client(
        DiscoveryDriverInterface $driver,
        int $cacheTtl,
        int $staleTtl,
        ?MutableClock $clock = null,
    ): DiscoveryClient {
        $clock ??= new MutableClock(1_000_000);
        $config = new DiscoveryConfig(
            cacheTtl: $cacheTtl,
            loadBalancer: DiscoveryConfig::LOAD_BALANCER_RANDOM,
            healthyOnly: true,
            clusters: '',
            groupName: '',
            namespaceId: '',
            staleTtl: $staleTtl,
        );

        return new DiscoveryClient(
            'demo',
            $driver,
            $config,
            null,
            static function () use ($clock): int {
                return $clock->now;
            },
        );
    }
}

final class MutableClock
{
    public function __construct(public int $now)
    {
    }
}

final class ScriptedDiscoveryDriver implements DiscoveryDriverInterface
{
    public int $calls = 0;

    /**
     * @param list<array<int, ServiceInstance>|Throwable> $script
     */
    public function __construct(private array $script)
    {
    }

    public function getInstances(string $serviceName): array
    {
        unset($serviceName);
        $this->calls++;
        $next = array_shift($this->script);
        if ($next instanceof Throwable) {
            throw $next;
        }

        return is_array($next) ? $next : [];
    }
}
