<?php

declare(strict_types=1);

namespace Swoolefy\Support\Nacos;

use Swoolefy\Support\ApplicationConfig;
use Swoolefy\Library\Nacos\Client;
use Swoolefy\Library\Nacos\ClientConfig;
use Symfony\Component\Yaml\Yaml;
use Swoolefy\Util\Log;

/**
 * 读取 nacos.yaml（Nacos 服务器连接）。
 *
 * application.yaml 固定位于 appPath，由 NacosServiceConfig / ApplicationConfig 读取。
 */
final class NacosConfig
{

    public function __construct(
        public readonly string $nacosFilePath,
        public readonly string $host,
        public readonly int $port,
        public readonly string $username,
        public readonly string $password,
        public readonly bool $authorizationBearer,
        public readonly string $dataId,
        public readonly string $group,
        public readonly string $tenant,
    ) {
    }

    /**
     * @param string $nacosFilePath nacos.yaml 完整路径（多项目可共用同一份）
     */
    public static function load(string $nacosFilePath): self
    {
        $yaml = is_file($nacosFilePath) ? (array) Yaml::parseFile($nacosFilePath) : [];
        $nacos = (array) ($yaml['nacos'] ?? []);

        return new self(
            nacosFilePath: $nacosFilePath,
            host: self::pickString($nacos, 'host', 'NACOS_HOST', '127.0.0.1'),
            port: self::pickInt($nacos, 'port', 'NACOS_PORT', 8848),
            username: self::pickString($nacos, 'username', 'NACOS_USERNAME', ''),
            password: self::pickString($nacos, 'password', 'NACOS_PASSWORD', ''),
            authorizationBearer: self::pickBool($nacos, 'authorization_bearer', 'NACOS_AUTHORIZATION_BEARER', false),
            dataId: self::pickString($nacos, 'data_id', 'NACOS_DATA_ID', 'swoolefy.env'),
            group: self::pickString($nacos, 'group', 'NACOS_GROUP', 'DEFAULT_GROUP'),
            tenant: self::pickString($nacos, 'tenant', 'NACOS_TENANT', ''),
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
