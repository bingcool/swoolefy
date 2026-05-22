<?php

declare(strict_types=1);

namespace Swoolefy\Support\Nacos;

use Common\Library\Nacos\Client;
use Common\Library\Nacos\ClientConfig;
use Symfony\Component\Yaml\Yaml;
use Swoolefy\Util\Log;

/**
 * 读取 APP_PATH/nacos.yaml，未配置项再回退环境变量（nacos / service 段）。
 */
final class NacosConfig
{
    public function __construct(
        public readonly string $appPath,
        public readonly string $yamlFile,
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
        /** @var array<string, mixed> */
        private readonly array $yaml = [],
    ) {
    }

    public static function load(?string $appPath = null): self
    {
        $appPath = $appPath ?? (defined('APP_PATH') ? APP_PATH : '');
        $yamlFile = rtrim($appPath, '/') . '/nacos.yaml';
        $yaml = is_file($yamlFile) ? (array) Yaml::parseFile($yamlFile) : [];
        $nacos = (array) ($yaml['nacos'] ?? []);
        $service = (array) ($yaml['service'] ?? []);

        return new self(
            appPath: $appPath,
            yamlFile: $yamlFile,
            host: self::pickString($nacos, 'host', 'NACOS_HOST', '127.0.0.1'),
            port: self::pickInt($nacos, 'port', 'NACOS_PORT', 8848),
            username: self::pickString($nacos, 'username', 'NACOS_USERNAME', ''),
            password: self::pickString($nacos, 'password', 'NACOS_PASSWORD', ''),
            authorizationBearer: self::pickBool($nacos, 'authorization_bearer', 'NACOS_AUTHORIZATION_BEARER', false),
            dataId: self::pickString($nacos, 'data_id', 'NACOS_DATA_ID', 'swoolefy.env'),
            group: self::pickString($nacos, 'group', 'NACOS_GROUP', 'DEFAULT_GROUP'),
            tenant: self::pickString($nacos, 'tenant', 'NACOS_TENANT', ''),
            serviceIp: self::pickString($service, 'ip', 'NACOS_SERVICE_IP', ''),
            servicePort: self::pickInt($service, 'port', 'NACOS_SERVICE_PORT', 0),
            serviceName: self::pickString($service, 'service_name', 'NACOS_SERVICE_NAME', ''),
            serviceNamespaceId: self::pickString($service, 'namespace_id', 'NACOS_SERVICE_NAMESPACE_ID', ''),
            serviceGroupName: self::pickString($service, 'group_name', 'NACOS_SERVICE_GROUP_NAME', ''),
            serviceWeight: (float) self::pickString($service, 'weight', 'NACOS_SERVICE_WEIGHT', '1'),
            serviceEphemeral: self::pickBool($service, 'ephemeral', 'NACOS_SERVICE_EPHEMERAL', true),
            yaml: $yaml,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function section(string $name): array
    {
        return (array) ($this->yaml[$name] ?? []);
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
