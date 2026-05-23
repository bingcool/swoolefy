<?php

declare(strict_types=1);

namespace Swoolefy\Support\Nacos\Discovery;

use Swoolefy\Support\ApplicationConfig;
use Swoolefy\Support\Nacos\NacosConfig;

/**
 * 服务发现配置（application.yaml → nacos.discovery_service_client）。
 */
final class DiscoveryConfig
{
    public const LOAD_BALANCER_RANDOM = 'random';
    public const LOAD_BALANCER_ROUND_ROBIN = 'round_robin';
    public const LOAD_BALANCER_WEIGHT = 'weight';

    public function __construct(
        public readonly int $cacheTtl,
        public readonly string $loadBalancer,
        public readonly bool $healthyOnly,
        public readonly string $clusters,
        public readonly string $groupName,
        public readonly string $namespaceId,
        public readonly string $defaultServiceName,
    ) {
    }

    public static function load(?NacosConfig $nacosConfig = null, ?ApplicationConfig $applicationConfig = null): self
    {
        $nacosConfig ??= NacosConfig::load();
        $applicationConfig ??= ApplicationConfig::load($nacosConfig->appPath);
        $discovery = $applicationConfig->nacosSection('discovery_service_client');

        $defaultService = ApplicationConfig::pickString($discovery, 'service_name', 'NACOS_DISCOVERY_SERVICE_NAME', '');
        if ('' === $defaultService) {
            $defaultService = $nacosConfig->serviceName;
        }

        $groupName = ApplicationConfig::pickString($discovery, 'group_name', 'NACOS_DISCOVERY_GROUP_NAME', '');
        if ('' === $groupName) {
            $groupName = $nacosConfig->serviceGroupName;
        }

        $namespaceId = ApplicationConfig::pickString($discovery, 'namespace_id', 'NACOS_DISCOVERY_NAMESPACE_ID', '');
        if ('' === $namespaceId) {
            $namespaceId = $nacosConfig->serviceNamespaceId;
        }

        return new self(
            cacheTtl: ApplicationConfig::pickInt($discovery, 'cache_ttl', 'NACOS_DISCOVERY_CACHE_TTL', 60),
            loadBalancer: strtolower(ApplicationConfig::pickString(
                $discovery,
                'load_balancer',
                'NACOS_DISCOVERY_LOAD_BALANCER',
                self::LOAD_BALANCER_RANDOM,
            )),
            healthyOnly: ApplicationConfig::pickBool($discovery, 'healthy_only', 'NACOS_DISCOVERY_HEALTHY_ONLY', true),
            clusters: ApplicationConfig::pickString($discovery, 'clusters', 'NACOS_DISCOVERY_CLUSTERS', ''),
            groupName: $groupName,
            namespaceId: $namespaceId,
            defaultServiceName: $defaultService,
        );
    }
}
