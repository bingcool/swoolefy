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
        /** @var array<string, mixed> */
        public readonly array $metadata,
    ) {
    }

    public static function load(): self
    {
        $appPath = ApplicationConfig::resolveAppPath();
        $section = ApplicationConfig::load()->nacosSection('service_register');

        return new self(
            appPath: $appPath,
            ip: ApplicationConfig::pickStringEnvFirst($section, 'ip', NacosConst::ENV_SERVICE_REGISTER_HOST, ''),
            port: (int) ApplicationConfig::pickStringEnvFirst($section, 'port', NacosConst::ENV_SERVICE_REGISTER_PORT, '0'),
            serviceName: self::getServiceName($section, 'service_name'),
            namespaceId: ApplicationConfig::pickStringEnvFirst($section, 'namespace_id', NacosConst::ENV_SERVICE_NAMESPACE_ID, ''),
            groupName: ApplicationConfig::pickStringEnvFirst($section, 'group_name', NacosConst::ENV_SERVICE_GROUP_NAME, ''),
            weight: (float) ApplicationConfig::pickStringEnvFirst($section, 'weight', NacosConst::ENV_SERVICE_WEIGHT, '1'),
            ephemeral: true,
            heartbeatInterval: (int) ApplicationConfig::pickStringEnvFirst($section, 'heartbeat_interval', NacosConst::ENV_SERVICE_HEARTBEAT_INTERVAL, '10'),
            metadata: self::getMetadata($section),
        );
    }

    protected static function getServiceName($yaml, $yamlKey): string
    {
        $name = ApplicationConfig::pickString($yaml, $yamlKey, NacosConst::ENV_SERVICE_NAME, '');
        if ('' === $name) {
            throw new \InvalidArgumentException('Invalid nacos.service_register.service_name');
        }

        return $name;
    }

    /**
     * application.yaml:
     * nacos:
     *   service_register:
     *     metadata:
     *       max_limit_request: 10000
     *       min_limit_request: 1000
     *
     * Nacos Open API 的 metadata 参数要求是 JSON 字符串；这里保留数组，
     * 由 ServiceRegister 在真正上报时统一编码，避免配置层混入传输格式。
     */
    private static function getMetadata(array $section): array
    {
        if (!array_key_exists('metadata', $section) || !is_array($section['metadata'])) {
            return [];
        }

        return $section['metadata'];
    }
}
