<?php

declare(strict_types=1);

namespace Swoolefy\Support\Nacos\Discovery\Model;

use Common\Library\Nacos\Provider\Instance\Model\Host;

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

    public function isAvailable(): bool
    {
        return '' !== $this->ip && $this->port > 0 && $this->enabled && $this->healthy;
    }
}
