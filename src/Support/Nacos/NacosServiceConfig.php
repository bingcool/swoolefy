<?php

declare(strict_types=1);

namespace Swoolefy\Support\Nacos;

use Swoolefy\Support\ApplicationConfig;

/**
 * 默认获取APP_PATH下Nacos服务注册配置（application.yaml → nacos.service_register）
 */
final class NacosServiceConfig
{
    public function __construct(
        public readonly string $appPath,
        public readonly string $ip,
        public readonly int $port,
        public readonly string $serviceName,
        public readonly string $namespaceId,
        public readonly string $groupName,
        public readonly float $weight,
        public readonly bool $ephemeral,
        public readonly int $heartbeatInterval,
    ) {
    }

    public static function load(?string $appPath = null): self
    {
        $appPath = $appPath ?? (defined('APP_PATH') ? APP_PATH : '');
        $section = ApplicationConfig::load($appPath)->nacosSection('service_register');

        return new self(
            appPath: $appPath,
            ip: ApplicationConfig::pickString($section, 'ip', 'NACOS_SERVICE_IP', ''),
            port: ApplicationConfig::pickInt($section, 'port', 'NACOS_SERVICE_PORT', 0),
            serviceName: ApplicationConfig::pickString($section, 'service_name', 'NACOS_SERVICE_NAME', ''),
            namespaceId: ApplicationConfig::pickString($section, 'namespace_id', 'NACOS_SERVICE_NAMESPACE_ID', ''),
            groupName: ApplicationConfig::pickString($section, 'group_name', 'NACOS_SERVICE_GROUP_NAME', ''),
            weight: (float) ApplicationConfig::pickString($section, 'weight', 'NACOS_SERVICE_WEIGHT', '1'),
            ephemeral: ApplicationConfig::pickBool($section, 'ephemeral', 'NACOS_SERVICE_EPHEMERAL', true),
            heartbeatInterval: ApplicationConfig::pickInt($section, 'heartbeat_interval', 'NACOS_SERVICE_HEARTBEAT_INTERVAL', 10),
        );
    }
}
