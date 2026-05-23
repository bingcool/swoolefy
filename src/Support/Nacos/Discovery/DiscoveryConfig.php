<?php

declare(strict_types=1);

namespace Swoolefy\Support\Nacos\Discovery;

use Swoolefy\Support\Nacos\NacosConfig;

/**
 * 服务发现配置（discovery 段，缺项回退环境变量）。
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

    public static function load(?NacosConfig $nacosConfig = null): self
    {
        $nacosConfig ??= NacosConfig::load();
        $discovery = $nacosConfig->section('discovery');
        $service = $nacosConfig->section('service');

        $defaultService = self::pickString($discovery, 'service_name', 'NACOS_DISCOVERY_SERVICE_NAME', '');
        if ('' === $defaultService) {
            $defaultService = self::pickString($service, 'service_name', 'NACOS_SERVICE_NAME', '');
        }

        $groupName = self::pickString($discovery, 'group_name', 'NACOS_DISCOVERY_GROUP_NAME', '');
        if ('' === $groupName) {
            $groupName = (string) $nacosConfig->serviceGroupName;
        }

        $namespaceId = self::pickString($discovery, 'namespace_id', 'NACOS_DISCOVERY_NAMESPACE_ID', '');
        if ('' === $namespaceId) {
            $namespaceId = (string) $nacosConfig->serviceNamespaceId;
        }

        return new self(
            cacheTtl: self::pickInt($discovery, 'cache_ttl', 'NACOS_DISCOVERY_CACHE_TTL', 60),
            loadBalancer: strtolower(self::pickString(
                $discovery,
                'load_balancer',
                'NACOS_DISCOVERY_LOAD_BALANCER',
                self::LOAD_BALANCER_RANDOM,
            )),
            healthyOnly: self::pickBool($discovery, 'healthy_only', 'NACOS_DISCOVERY_HEALTHY_ONLY', true),
            clusters: self::pickString($discovery, 'clusters', 'NACOS_DISCOVERY_CLUSTERS', ''),
            groupName: $groupName,
            namespaceId: $namespaceId,
            defaultServiceName: $defaultService,
        );
    }

    private static function pickString(array $yaml, string $yamlKey, string $envKey, string $default): string
    {
        if (array_key_exists($yamlKey, $yaml) && '' !== (string) $yaml[$yamlKey]) {
            return (string) $yaml[$yamlKey];
        }

        $env = getenv($envKey);
        if (false !== $env && '' !== $env) {
            return (string) $env;
        }

        return $default;
    }

    private static function pickInt(array $yaml, string $yamlKey, string $envKey, int $default): int
    {
        if (array_key_exists($yamlKey, $yaml) && is_numeric($yaml[$yamlKey])) {
            return (int) $yaml[$yamlKey];
        }

        $env = getenv($envKey);
        if (false !== $env && is_numeric($env)) {
            return (int) $env;
        }

        return $default;
    }

    private static function pickBool(array $yaml, string $yamlKey, string $envKey, bool $default): bool
    {
        if (array_key_exists($yamlKey, $yaml)) {
            return filter_var($yaml[$yamlKey], FILTER_VALIDATE_BOOLEAN);
        }

        $env = getenv($envKey);
        if (false !== $env) {
            return filter_var($env, FILTER_VALIDATE_BOOLEAN);
        }

        return $default;
    }
}
