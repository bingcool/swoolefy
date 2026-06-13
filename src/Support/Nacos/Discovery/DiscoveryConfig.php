<?php

declare(strict_types=1);

namespace Swoolefy\Support\Nacos\Discovery;

use Swoolefy\Support\ApplicationConfig;
use Swoolefy\Support\Nacos\NacosServiceRegisterConfig;

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
    ) {
    }

    public static function load(
        ?NacosServiceRegisterConfig $serviceRegisterConfig = null,
        ?ApplicationConfig          $applicationConfig = null,
    ): self {
        $serviceRegisterConfig ??= NacosServiceRegisterConfig::load();
        $applicationConfig ??= ApplicationConfig::load();
        $discovery = $applicationConfig->nacosSection('discovery_service_client');

        // 优先取NACOS_DISCOVERY_GROUP_NAME值
        $groupName = ApplicationConfig::pickStringEnvFirst($discovery, 'group_name', 'NACOS_SERVICE_GROUP_NAME', '');
        if ('' === $groupName) {
            $groupName = $serviceRegisterConfig->groupName;
        }

        // 优先取NACOS_DISCOVERY_NAMESPACE_ID值
        $namespaceId = ApplicationConfig::pickStringEnvFirst($discovery, 'namespace_id', 'NACOS_SERVICE_NAMESPACE_ID', '');
        if ('' === $namespaceId) {
            $namespaceId = $serviceRegisterConfig->namespaceId;
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
        );
    }
}
