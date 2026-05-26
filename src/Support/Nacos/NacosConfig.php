<?php

declare(strict_types=1);

namespace Swoolefy\Support\Nacos;

use Swoolefy\Exception\NacosMonitorException;
use Swoolefy\Support\ApplicationConfig;
use Swoolefy\Library\Nacos\Client;
use Swoolefy\Library\Nacos\ClientConfig;
use Symfony\Component\Yaml\Yaml;

/**
 * 读取 nacos.yaml（Nacos 服务器连接）
 *
 * 路径由常量 NACOS_FILE_PATH 指定（cli.php 可从环境变量注入）；未设置时回退为 APP_PATH/nacos.yaml。
 * application.yaml 由 ApplicationConfig / NacosServiceConfig 通过 APP_PATH 读取。
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

    public static function load(): self
    {
        $nacosFilePath = self::resolveNacosFilePath();
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

    public static function resolveNacosFilePath(): string
    {
        if (!defined('NACOS_FILE_PATH')) {
            throw NacosMonitorException::throw("Please define const `NACOS_FILE_PATH`");
        }
        $path = NACOS_FILE_PATH;
        if (is_string($path) && '' !== $path && is_file($path)) {
            return $path;
        } else {
            throw NacosMonitorException::throw("Constant Of `NACOS_FILE_PATH` must be a nacos.yaml file");
        }
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

    public function createClient(): Client
    {
        return new Client(new ClientConfig($this->toClientConfigArray()), NacosLogger::get());
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
