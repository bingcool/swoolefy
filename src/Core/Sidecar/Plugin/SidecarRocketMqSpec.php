<?php

declare(strict_types=1);

namespace Swoolefy\Core\Sidecar\Plugin;

use Swoolefy\Core\Sidecar\SidecarException;

/**
 * RocketMQ 进程的字段。端点、密钥和允许的 Topic 只在这个插件上出现。
 */
final class SidecarRocketMqSpec implements SidecarPluginSpec
{
    public static function name(): string
    {
        return 'rocketmq';
    }

    public static function keys(): array
    {
        return [
            'sidecar_rocketmq_proxy_endpoint',
            'sidecar_rocketmq_access_key',
            'sidecar_rocketmq_secret_key',
            'sidecar_rocketmq_allowed_topics',
        ];
    }

    public static function fields(array $row): array
    {
        $name = is_string($row['sidecar_name'] ?? null) ? $row['sidecar_name'] : '';
        $endpoint = self::requireString($row, 'sidecar_rocketmq_proxy_endpoint', $name);
        $accessKey = self::requireString($row, 'sidecar_rocketmq_access_key', $name);
        $secretKey = self::requireString($row, 'sidecar_rocketmq_secret_key', $name);
        self::assertEndpoint($endpoint, $name);

        return [
            'sidecar_rocketmq_proxy_endpoint' => $endpoint,
            'sidecar_rocketmq_access_key' => $accessKey,
            'sidecar_rocketmq_secret_key' => $secretKey,
            'sidecar_rocketmq_allowed_topics' => self::requireTopics($row, $name),
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function requireString(array $row, string $key, string $name): string
    {
        $value = $row[$key] ?? '';
        if (!is_string($value) || str_contains($value, "\0")) {
            throw new SidecarException(self::label($name, $key) . ' must be a string');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function requireTopics(array $row, string $name): string
    {
        $raw = self::requireString($row, 'sidecar_rocketmq_allowed_topics', $name);
        $trimmed = trim($raw);
        if ($trimmed === '' || $trimmed === '*') {
            return $trimmed;
        }
        $parts = explode(',', $trimmed);
        $clean = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || str_contains($part, '*')) {
                throw new SidecarException(self::label($name, 'sidecar_rocketmq_allowed_topics') . ' is invalid');
            }
            $clean[] = $part;
        }

        return implode(',', $clean);
    }

    private static function assertEndpoint(string $endpoint, string $name): void
    {
        if ($endpoint === '' || str_contains($endpoint, '://') || str_contains($endpoint, '/')) {
            throw new SidecarException(self::label($name, 'sidecar_rocketmq_proxy_endpoint') . ' must be host:port');
        }
        $parts = explode(':', $endpoint);
        if (count($parts) !== 2 || $parts[0] === '' || !ctype_digit($parts[1])) {
            throw new SidecarException(self::label($name, 'sidecar_rocketmq_proxy_endpoint') . ' must be host:port');
        }
        $port = (int) $parts[1];
        if ($port < 1 || $port > 65535) {
            throw new SidecarException(self::label($name, 'sidecar_rocketmq_proxy_endpoint') . ' must be host:port');
        }
    }

    private static function label(string $name, string $key): string
    {
        return $name === '' ? $key : $name . '.' . $key;
    }
}
