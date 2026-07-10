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

namespace Swoolefy\Support\Nacos\Discovery\Model;

use Swoolefy\Library\Nacos\Provider\Instance\Model\Host;

/**
 * 可被发现、可被负载均衡调度的服务实例。
 */
final class ServiceInstance
{
    public function __construct(
        public readonly string $serviceName,
        public readonly string $ip,
        public readonly int $port,
        public readonly float $weight = 1.0,
        public readonly bool $healthy = true,
        public readonly bool $enabled = true,
        public readonly string $instanceId = '',
        public readonly string $clusterName = '',
        /** @var array<string, mixed> */
        public readonly array $metadata = [],
    ) {
    }

    public static function fromNacosHost(Host $host, string $serviceName = ''): self
    {
        $weight = (float) $host->getWeight();
        if ($weight <= 0) {
            $weight = 1.0;
        }

        return new self(
            serviceName: $serviceName !== '' ? $serviceName : (string) $host->getServiceName(),
            ip: (string) $host->getIp(),
            port: (int) $host->getPort(),
            weight: $weight,
            healthy: (bool) $host->getHealthy(),
            enabled: (bool) $host->getEnabled(),
            instanceId: (string) $host->getInstanceId(),
            clusterName: (string) $host->getClusterName(),
            metadata: $host->getMetadata() ?? [],
        );
    }

    public function getAddress(): string
    {
        return $this->ip . ':' . $this->port;
    }

    public function getUri(string $scheme = 'http'): string
    {
        return $scheme . '://' . $this->getAddress();
    }

    /**
     * 获取 Nacos 实例 metadata。
     *
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getMetadataValue(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }

    public function isAvailable(): bool
    {
        return '' !== $this->ip && $this->port > 0 && $this->enabled && $this->healthy;
    }
}
