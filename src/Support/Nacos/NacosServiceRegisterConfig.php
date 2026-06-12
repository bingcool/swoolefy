<?php

declare(strict_types=1);

namespace Swoolefy\Support\Nacos;

use Swoolefy\Support\ApplicationConfig;

/**
 * 本机 Nacos 服务注册配置（APP_PATH/application.yaml → nacos.service_register）。
 */
final class NacosServiceRegisterConfig
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

    public static function load(): self
    {
        $appPath = ApplicationConfig::resolveAppPath();
        $section = ApplicationConfig::load()->nacosSection('service_register');

        return new self(
            appPath: $appPath,
            ip: self::pickEnvFirst($section, 'ip', 'NACOS_SERVICE_REGISTER_HOST', ''),
            port: (int) self::pickEnvFirst($section, 'port', 'NACOS_SERVICE_REGISTER_PORT', '0'),
            serviceName: self::pickEnvFirst($section, 'service_name', 'NACOS_SERVICE_NAME', ''),
            namespaceId: self::pickEnvFirst($section, 'namespace_id', 'NACOS_SERVICE_NAMESPACE_ID', ''),
            groupName: self::pickEnvFirst($section, 'group_name', 'NACOS_SERVICE_GROUP_NAME', ''),
            weight: (float) self::pickEnvFirst($section, 'weight', 'NACOS_SERVICE_WEIGHT', '1'),
            ephemeral: self::pickEnvFirstBool($section, 'ephemeral', 'NACOS_SERVICE_EPHEMERAL', true),
            heartbeatInterval: (int) self::pickEnvFirst($section, 'heartbeat_interval', 'NACOS_SERVICE_HEARTBEAT_INTERVAL', '10'),
        );
    }

    /**
     * 优先环境变量 → yaml配置 → 默认值
     */
    private static function pickEnvFirst(array $yaml, string $yamlKey, string $envKey, string $default): string
    {
        $env = getenv($envKey);
        if (false !== $env && '' !== $env) {
            return (string) $env;
        }

        if (array_key_exists($yamlKey, $yaml) && '' !== (string) $yaml[$yamlKey]) {
            return (string) $yaml[$yamlKey];
        }

        return $default;
    }

    /**
     * 优先环境变量 → yaml配置 → 默认值（布尔类型）
     */
    private static function pickEnvFirstBool(array $yaml, string $yamlKey, string $envKey, bool $default): bool
    {
        $env = getenv($envKey);
        if (false !== $env) {
            return filter_var($env, FILTER_VALIDATE_BOOLEAN);
        }

        if (array_key_exists($yamlKey, $yaml)) {
            return filter_var($yaml[$yamlKey], FILTER_VALIDATE_BOOLEAN);
        }

        return $default;
    }
}
