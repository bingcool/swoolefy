<?php

declare(strict_types=1);

namespace Swoolefy\Support\Nacos;

use Dotenv\Dotenv;
use Dotenv\Exception\InvalidFileException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\RequestException;
use Swoolefy\Exception\NacosMonitorException;

/**
 * Nacos 常用操作入口（启动阶段可用，不依赖 Log 组件注册）。
 */
final class NacosFactory
{
    private const CONFIG_API_PATH = 'nacos/v1/cs/configs';

    private const AUTH_LOGIN_PATH = 'nacos/v1/auth/login';

    /**
     * 从 Nacos 配置中心拉取远程配置，校验 .env 格式后原子写入 APP_PATH/.env。
     *
     * @param string $nacosYamlFile nacos.yaml 路径（读取 Nacos 连接与 data_id/group）
     * @return string 写入后的 .env 绝对路径
     */
    public static function fetchConfigToEnv(string $nacosYamlFile): string
    {
        $nacosYamlFile = self::resolveNacosYamlPath($nacosYamlFile);
        if (!is_file($nacosYamlFile)) {
            throw NacosMonitorException::throw('nacos.yaml not found: ' . $nacosYamlFile);
        }

        if (!defined('APP_PATH') || '' === APP_PATH) {
            throw NacosMonitorException::throw('APP_PATH is not defined');
        }

        $envFile = rtrim( APP_PATH, '/') . '/.env';
        $nacosConfig = NacosConfig::load(dirname($nacosYamlFile));

        try {
            $content = self::fetchConfigFromNacos($nacosConfig);
        } catch (RequestException|\Throwable $e) {
            throw NacosMonitorException::throw('Nacos config fetch failed: ' . $e->getMessage(), 0, [], $e);
        }

        self::assertValidEnvContent($content);
        self::writeEnvFileAtomically($envFile, $content);

        return $envFile;
    }

    /**
     * 使用 Guzzle 调用 Nacos Open API 拉取配置正文。
     */
    private static function fetchConfigFromNacos(NacosConfig $config): string
    {
        $client = new GuzzleClient([
            'base_uri' => sprintf('http://%s:%d/', $config->host, $config->port),
            'http_errors' => false,
            'timeout' => 60,
            'connect_timeout' => 30,
            'headers' => [
                'Accept' => 'text/plain, application/json, */*',
            ],
        ]);

        $query = [
            'dataId' => $config->dataId,
            'group' => $config->group,
            'tenant' => $config->tenant,
        ];

        $headers = [];
        if ('' !== $config->username && '' !== $config->password) {
            $accessToken = self::fetchAccessToken($client, $config);
            $query['accessToken'] = $accessToken;
            if ($config->authorizationBearer) {
                $headers['Authorization'] = 'Bearer ' . $accessToken;
            }
        }

        $response = $client->get(self::CONFIG_API_PATH, [
            'query' => $query,
            'headers' => $headers,
        ]);

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        if ($status < 200 || $status >= 300) {
            throw NacosMonitorException::throw(sprintf(
                'Nacos config get failed, HTTP %d: %s',
                $status,
                '' !== $body ? $body : 'empty response',
            ));
        }

        if ('' === trim($body)) {
            throw NacosMonitorException::throw(sprintf(
                'Nacos config content is empty: dataId=%s, group=%s',
                $config->dataId,
                $config->group,
            ));
        }

        return $body;
    }

    private static function fetchAccessToken(GuzzleClient $client, NacosConfig $config): string
    {
        $response = $client->post(self::AUTH_LOGIN_PATH, [
            'form_params' => [
                'username' => $config->username,
                'password' => $config->password,
            ],
        ]);

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        if ($status < 200 || $status >= 300) {
            throw NacosMonitorException::throw(sprintf(
                'Nacos auth login failed, HTTP %d: %s',
                $status,
                '' !== $body ? $body : 'empty response',
            ));
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw NacosMonitorException::throw('Invalid Nacos login response: ' . $e->getMessage());
        }

        $token = (string) ($decoded['accessToken'] ?? '');
        if ('' === $token && isset($decoded['data']) && is_string($decoded['data'])) {
            $parts = explode(' ', trim($decoded['data']));
            $token = (string)($parts[1] ?? '');
        }

        if ('' === $token) {
            throw NacosMonitorException::throw('Nacos login response missing accessToken');
        }

        return $token;
    }

    /**
     * 使用 phpdotenv 解析校验内容是否为合法 .env 格式。
     */
    private static function assertValidEnvContent(string $content): void
    {
        try {
            Dotenv::parse($content);
        } catch (InvalidFileException $e) {
            throw NacosMonitorException::throw(
                'Nacos config is not valid .env format: ' . $e->getMessage(),
                0,
                [],
                $e,
            );
        }
    }

    /** 原子写入 .env（先写临时文件再 rename） */
    private static function writeEnvFileAtomically(string $envFile, string $content): void
    {
        $dir = dirname($envFile);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw NacosMonitorException::throw('Failed to create directory: ' . $dir);
        }

        $tmpFile = $envFile . '.' . getmypid() . '.tmp';
        if (false === file_put_contents($tmpFile, $content)) {
            throw NacosMonitorException::throw('Failed to write temp env file: ' . $tmpFile);
        }

        if (!rename($tmpFile, $envFile)) {
            @unlink($tmpFile);
            throw NacosMonitorException::throw('Failed to replace env file: ' . $envFile);
        }
    }

    private static function resolveNacosYamlPath(string $nacosYamlFile): string
    {
        $path = trim($nacosYamlFile);
        if ('' === $path) {
            throw NacosMonitorException::throw('nacosYamlFile is empty');
        }

        if (str_starts_with($path, '/')) {
            return $path;
        }

        $base = defined('APP_PATH') && '' !== APP_PATH
            ? APP_PATH
            : (string) getcwd();

        return rtrim($base, '/') . '/' . ltrim(str_replace('\\', '/', $path), '/');
    }
}
