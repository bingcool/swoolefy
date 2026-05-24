<?php

declare(strict_types=1);

namespace Swoolefy\Support\Nacos;

use Swoolefy\Support\ApplicationConfig;
use Swoolefy\Library\Nacos\Client;
use Swoolefy\Library\Nacos\ClientConfig;
use Symfony\Component\Yaml\Yaml;
use Swoolefy\Util\Log;

/**
 * 读取 APP_PATH/nacos.yaml（服务器连接）+ application.yaml（service_register 段）。
 */
final class NacosConfig
{
    public function __construct(
        public readonly string $appPath,
        public readonly string $yamlFile,
        public readonly string $applicationYamlFile,
        public readonly string $host,
        public readonly int $port,
        public readonly string $username,
        public readonly string $password,
        public readonly bool $authorizationBearer,
        public readonly string $dataId,
        public readonly string $group,
        public readonly string $tenant,
        public readonly string $serviceIp,
        public readonly int $servicePort,
        public readonly string $serviceName,
        public readonly string $serviceNamespaceId,
        public readonly string $serviceGroupName,
        public readonly float $serviceWeight,
        public readonly bool $serviceEphemeral,
        public readonly int $serviceHeartbeatInterval,
    ) {
    }

    public static function load(?string $appPath = null): self
    {
        $appPath = $appPath ?? (defined('APP_PATH') ? APP_PATH : '');
        $yamlFile = rtrim($appPath, '/') . '/nacos.yaml';
        $applicationYamlFile = rtrim($appPath, '/') . '/application.yaml';
        $yaml = is_file($yamlFile) ? (array) Yaml::parseFile($yamlFile) : [];
        $nacos = (array) ($yaml['nacos'] ?? []);
        $serviceRegister = ApplicationConfig::load($appPath)->nacosSection('service_register');

        return new self(
            appPath: $appPath,
            yamlFile: $yamlFile,
            applicationYamlFile: $applicationYamlFile,
            host: self::pickString($nacos, 'host', 'NACOS_HOST', '127.0.0.1'),
            port: self::pickInt($nacos, 'port', 'NACOS_PORT', 8848),
            username: self::pickString($nacos, 'username', 'NACOS_USERNAME', ''),
            password: self::pickString($nacos, 'password', 'NACOS_PASSWORD', ''),
            authorizationBearer: self::pickBool($nacos, 'authorization_bearer', 'NACOS_AUTHORIZATION_BEARER', false),
            dataId: self::pickString($nacos, 'data_id', 'NACOS_DATA_ID', 'swoolefy.env'),
            group: self::pickString($nacos, 'group', 'NACOS_GROUP', 'DEFAULT_GROUP'),
            tenant: self::pickString($nacos, 'tenant', 'NACOS_TENANT', ''),
            serviceIp: ApplicationConfig::pickString($serviceRegister, 'ip', 'NACOS_SERVICE_IP', ''),
            servicePort: ApplicationConfig::pickInt($serviceRegister, 'port', 'NACOS_SERVICE_PORT', 0),
            serviceName: ApplicationConfig::pickString($serviceRegister, 'service_name', 'NACOS_SERVICE_NAME', ''),
            serviceNamespaceId: ApplicationConfig::pickString($serviceRegister, 'namespace_id', 'NACOS_SERVICE_NAMESPACE_ID', ''),
            serviceGroupName: ApplicationConfig::pickString($serviceRegister, 'group_name', 'NACOS_SERVICE_GROUP_NAME', ''),
            serviceWeight: (float) ApplicationConfig::pickString($serviceRegister, 'weight', 'NACOS_SERVICE_WEIGHT', '1'),
            serviceEphemeral: ApplicationConfig::pickBool($serviceRegister, 'ephemeral', 'NACOS_SERVICE_EPHEMERAL', true),
            serviceHeartbeatInterval: ApplicationConfig::pickInt($serviceRegister, 'heartbeat_interval', 'NACOS_SERVICE_HEARTBEAT_INTERVAL', 10),
        );
    }

    public function toClientConfigArray(): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'username' => $this->username,
            'password' => $this->password,
            'authorizationBearer' => $this->authorizationBearer || ('' !== $this->username && '' !== $this->password),
            'useCoroutinePool' => $this->useCoroutinePool(),
        ];
    }

    public function useCoroutinePool(): bool
    {
        return \extension_loaded('swoole') && \Swoole\Coroutine::getCid() > 0;
    }

    public function createClient(?Log $logger = null): Client
    {
        return new Client(new ClientConfig($this->toClientConfigArray()), $logger);
    }

    private static function pickString(array $yaml, string $yamlKey, string $envKey, string $default): string
    {
        return ApplicationConfig::pickString($yaml, $yamlKey, $envKey, $default);
    }

    private static function pickInt(array $yaml, string $yamlKey, string $envKey, int $default): int
    {
        return ApplicationConfig::pickInt($yaml, $yamlKey, $envKey, $default);
    }

    private static function pickBool(array $yaml, string $yamlKey, string $envKey, bool $default): bool
    {
        return ApplicationConfig::pickBool($yaml, $yamlKey, $envKey, $default);
    }
}
