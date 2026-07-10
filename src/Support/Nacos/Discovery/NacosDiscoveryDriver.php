<?php

declare(strict_types=1);

namespace Swoolefy\Support\Nacos\Discovery;

use Swoolefy\Exception\NacosDiscoveryException;
use Swoolefy\Library\Nacos\Provider\Instance\Model\Host;
use Swoolefy\Support\Nacos\Discovery\Contract\DiscoveryDriverInterface;
use Swoolefy\Support\Nacos\Discovery\Model\ServiceInstance;
use Swoolefy\Support\Nacos\NacosConfig;
use Swoolefy\Support\Nacos\NacosLogger;
use Swoolefy\Util\Log;

/**
 * 基于 Nacos 注册中心的服务发现驱动。
 */
final class NacosDiscoveryDriver implements DiscoveryDriverInterface
{
    private readonly Log $logger;

    public function __construct(
        private readonly NacosConfig $nacosConfig,
        private readonly DiscoveryConfig $discoveryConfig,
    ) {
        $this->logger = NacosLogger::get();
    }

    public function getInstances(string $serviceName): array
    {
        if ('' === $serviceName) {
            throw NacosDiscoveryException::throw('service name is required for discovery');
        }

        $client = $this->nacosConfig->createClient();
        $response = $client->instance->list(
            $serviceName,
            $this->discoveryConfig->groupName,
            $this->discoveryConfig->namespaceId,
            $this->discoveryConfig->clusters,
            $this->discoveryConfig->healthyOnly,
        );

        $instances = [];
        foreach ($response->getHosts() as $host) {
            if (!$host instanceof Host) {
                continue;
            }

            $instance = ServiceInstance::fromNacosHost($host, $serviceName);
            if ($instance->isAvailable()) {
                $instances[] = $instance;
            }
        }

        if ([] === $instances && $this->discoveryConfig->healthyOnly) {
            $this->logger->warning(sprintf('no healthy nacos instances for service=%s', $serviceName));
        }

        return $instances;
    }
}
