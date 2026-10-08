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

namespace Swoolefy\Support\Nacos\Discovery;

use Swoolefy\Exception\NacosDiscoveryException;
use Swoolefy\Support\Nacos\Discovery\Contract\DiscoveryDriverInterface;
use Swoolefy\Support\Nacos\Discovery\Model\ServiceInstance;
use Swoolefy\Support\Nacos\LoadBalancer\Contract\LoadBalancerInterface;
use Swoolefy\Support\Nacos\LoadBalancer\LoadBalancerFactory;
use Swoolefy\Support\Nacos\NacosConfig;
use Swoolefy\Support\Nacos\NacosLogger;

/**
 * Nacos 服务发现客户端：实例缓存 + 负载均衡选择。
 *
 * 参考 imi-service DiscoveryClient / ServiceDiscovery 设计。
 *
 * @see https://github.com/imiphp/imi-service
 */
final class DiscoveryClient
{
    /**
     * 缓存服务实例
     * @var ServiceInstance[]
     */
    private array $instances = [];

    /** 上次拉取结束时间（成功、成功空列表、捕获到的异常都更新）。 */
    private int $lastFetchAt = 0;

    /** 上次 driver 正常返回的时间。空列表也算成功。失败不更新。 */
    private int $lastSuccessAt = 0;

    private bool $isFetching = false;

    /** @var null|\Closure(): int */
    private ?\Closure $clock;

    private ?LoadBalancerInterface $loadBalancer = null;

    public function __construct(
        private readonly string $serviceName,
        private readonly DiscoveryDriverInterface $driver,
        private readonly DiscoveryConfig $discoveryConfig,
        ?LoadBalancerInterface $loadBalancer = null,
        ?\Closure $clock = null,
    ) {
        if ('' === $this->serviceName) {
            throw NacosDiscoveryException::throw('discovery service name is required');
        }

        $this->loadBalancer = $loadBalancer;
        $this->clock = $clock;
    }

    public static function create(
        string $serviceName,
        ?NacosConfig $nacosConfig = null,
        ?DiscoveryConfig $discoveryConfig = null,
        ?LoadBalancerInterface $loadBalancer = null,
    ): self {
        $nacosConfig ??= NacosConfig::load();
        $discoveryConfig ??= DiscoveryConfig::load();

        if ($serviceName === '') {
            throw NacosDiscoveryException::throw('discovery service_name is not configured');
        }

        $driver = new NacosDiscoveryDriver($nacosConfig, $discoveryConfig);
        $client = new self($serviceName, $driver, $discoveryConfig, $loadBalancer);

        if (null === $loadBalancer) {
            $client->setLoadBalancer(LoadBalancerFactory::create($discoveryConfig->loadBalancer, $client));
        }

        return $client;
    }

    public function getServiceName(): string
    {
        return $this->serviceName;
    }

    public function getDiscoveryConfig(): DiscoveryConfig
    {
        return $this->discoveryConfig;
    }

    public function getDriver(): DiscoveryDriverInterface
    {
        return $this->driver;
    }

    /**
     * @return ServiceInstance[]
     */
    public function getInstances(bool $refresh = false): array
    {
        $cacheTtl = $this->discoveryConfig->cacheTtl;
        if ($refresh || $cacheTtl <= 0) {
            return $this->fetchInstances();
        }

        if (!$this->isFetching && ($this->now() - $this->lastFetchAt) >= $cacheTtl) {
            $this->fetchInstances();
        }

        return $this->instances;
    }

    public function refresh(): array
    {
        return $this->getInstances(true);
    }

    public function getLoadBalancer(): LoadBalancerInterface
    {
        if (null === $this->loadBalancer) {
            $this->loadBalancer = LoadBalancerFactory::create(
                $this->discoveryConfig->loadBalancer,
                $this,
            );
        }

        return $this->loadBalancer;
    }

    public function setLoadBalancer(LoadBalancerInterface $loadBalancer): self
    {
        $this->loadBalancer = $loadBalancer;

        return $this;
    }

    public function choose(): ?ServiceInstance
    {
        $instance = $this->getLoadBalancer()->choose();
        if (null !== $instance) {
            return $instance;
        }

        $this->refresh();

        return $this->getLoadBalancer()->choose();
    }

    public function chooseUri(string $scheme = 'http'): ?string
    {
        return $this->choose()?->getUri($scheme);
    }

    /**
     * @return ServiceInstance[]
     */
    private function fetchInstances(): array
    {
        $this->isFetching = true;
        $now = $this->now();
        try {
            try {
                $fetched = $this->driver->getInstances($this->serviceName);
            } catch (\Throwable) {
                $this->lastFetchAt = $now;
                if ($this->canServeStale($now)) {
                    $this->logWarning(sprintf(
                        'nacos discovery refresh failed, serve stale service=%s age=%d',
                        $this->serviceName,
                        $now - $this->lastSuccessAt,
                    ));

                    return $this->instances;
                }

                $this->instances = [];
                $this->logWarning(sprintf(
                    'nacos discovery stale expired service=%s',
                    $this->serviceName,
                ));

                return $this->instances;
            }

            $this->instances = $fetched;
            $this->lastSuccessAt = $now;
            $this->lastFetchAt = $now;
            if ($fetched === []) {
                $this->logWarning(sprintf(
                    'nacos discovery empty service=%s',
                    $this->serviceName,
                ));
            }
        } finally {
            $this->isFetching = false;
        }

        return $this->instances;
    }

    private function now(): int
    {
        if ($this->clock !== null) {
            return (int) ($this->clock)();
        }

        return time();
    }

    private function canServeStale(int $now): bool
    {
        $staleTtl = $this->discoveryConfig->staleTtl;

        return $this->instances !== []
            && $staleTtl > 0
            && $this->lastSuccessAt > 0
            && ($now - $this->lastSuccessAt) <= $staleTtl;
    }

    private function logWarning(string $message): void
    {
        try {
            NacosLogger::get()->warning($message);
        } catch (\Throwable) {
            // 未注册 nacos_log 时不改变发现结果
        }
    }
}
