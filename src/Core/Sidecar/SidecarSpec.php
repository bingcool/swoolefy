<?php

declare(strict_types=1);

namespace Swoolefy\Core\Sidecar;

/**
 * 一份外部进程声明。fromRow() 返回时路径和数值都已经校验过。
 */
final class SidecarSpec
{
    /** @var list<string> */
    private const KEYS = [
        'sidecar_name',
        'sidecar_enable',
        'sidecar_exec_binary',
        'sidecar_args',
        'sidecar_socket_path',
        'sidecar_max_frame_bytes',
        'sidecar_default_timeout_ms',
        'sidecar_max_timeout_ms',
        'sidecar_max_inflight',
        'sidecar_shutdown_grace_ms',
        'sidecar_plugins',
        'sidecar_rocketmq_proxy_endpoint',
        'sidecar_rocketmq_access_key',
        'sidecar_rocketmq_secret_key',
        'sidecar_rocketmq_allowed_topics',
    ];

    /** @var list<string> */
    private const SKIP_ENV = [];

    /**
     * @param list<string> $args
     * @param array<string, string> $environment 已是 SIDECAR_* => 非空字符串
     */
    public function __construct(
        public readonly string $name,
        public readonly string $binary,
        public readonly array $args,
        public readonly string $socketPath,
        public readonly int $maxFrameBytes,
        public readonly int $defaultTimeoutMs,
        public readonly int $maxTimeoutMs,
        public readonly int $maxInflight,
        public readonly int $shutdownGraceMs,
        public readonly string $plugins,
        public readonly array $environment,
    ) {
    }

    /** @return array<string, string> */
    public function environment(): array
    {
        return $this->environment;
    }

    /**
     * 未启用返回 null，不检查二进制。启用项校验失败抛 SidecarException。
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row, string $runtimeDir): ?self
    {
        self::assertKeys($row);
        $row = self::applyDefaults($row);
        $enabled = self::parseEnable($row['sidecar_enable'], (string) ($row['sidecar_name'] ?? ''));
        if (!$enabled) {
            return null;
        }

        $name = self::requireName($row);
        $binary = self::requireBinary($row, $name);
        $args = self::requireArgs($row, $name);
        $socketConfigured = self::requireSocket($row, $name);
        $maxTimeoutMs = self::requireInt($row, 'sidecar_max_timeout_ms', 1, 300000, $name);
        $defaultTimeoutMs = self::requireInt($row, 'sidecar_default_timeout_ms', 1, $maxTimeoutMs, $name);
        $maxFrameBytes = self::requireInt($row, 'sidecar_max_frame_bytes', 1024, 16777216, $name);
        $maxInflight = self::requireInt($row, 'sidecar_max_inflight', 1, 1024, $name);
        $shutdownGraceMs = self::requireInt($row, 'sidecar_shutdown_grace_ms', 0, 30000, $name);
        $plugins = self::requirePlugins($row, $name);
        $endpoint = self::requireString($row, 'sidecar_rocketmq_proxy_endpoint', $name);
        $accessKey = self::requireString($row, 'sidecar_rocketmq_access_key', $name);
        $secretKey = self::requireString($row, 'sidecar_rocketmq_secret_key', $name);
        $allowedTopics = self::requireTopics($row, $name);
        if (in_array('rocketmq', explode(',', $plugins), true)) {
            self::assertEndpoint($endpoint, $name);
        }

        $dir = $runtimeDir;
        if ($socketConfigured === '' && $dir === '') {
            $dir = defined('APP_PATH') ? APP_PATH . '/runtime' : '';
        }
        $socketPath = SocketEndpoint::resolve($name, $socketConfigured, $dir);
        $row['sidecar_socket_path'] = $socketPath;
        $row['sidecar_enable'] = true;
        $row['sidecar_args'] = $args;
        $row['sidecar_rocketmq_allowed_topics'] = $allowedTopics;

        return new self(
            name: $name,
            binary: $binary,
            args: $args,
            socketPath: $socketPath,
            maxFrameBytes: $maxFrameBytes,
            defaultTimeoutMs: $defaultTimeoutMs,
            maxTimeoutMs: $maxTimeoutMs,
            maxInflight: $maxInflight,
            shutdownGraceMs: $shutdownGraceMs,
            plugins: $plugins,
            environment: self::encodeRow($row, $socketPath),
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, string>
     */
    public static function encodeRow(array $row, string $resolvedSocketPath): array
    {
        $row['sidecar_socket_path'] = $resolvedSocketPath;
        $env = [];
        foreach ($row as $key => $value) {
            if (!is_string($key) || !str_starts_with($key, 'sidecar_')) {
                throw new SidecarException('sidecar config key must start with sidecar_');
            }
            if (in_array($key, self::SKIP_ENV, true)) {
                continue;
            }
            $encoded = self::encodeEnvValue($key, $value);
            if ($encoded === '') {
                continue;
            }
            $env['SIDECAR_' . strtoupper(substr($key, strlen('sidecar_')))] = $encoded;
        }

        return $env;
    }

    private static function encodeEnvValue(string $key, mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_string($value)) {
            return $value;
        }
        if ($key === 'sidecar_args' && is_array($value)) {
            return json_encode(array_values($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        throw new SidecarException("unsupported sidecar value type: {$key}");
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function assertKeys(array $row): void
    {
        foreach ($row as $key => $_) {
            if (!is_string($key) || !str_starts_with($key, 'sidecar_')) {
                throw new SidecarException('sidecar config key must start with sidecar_');
            }
            if (!in_array($key, self::KEYS, true)) {
                throw new SidecarException('unknown sidecar config key: ' . $key);
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function applyDefaults(array $row): array
    {
        $defaults = [
            'sidecar_enable' => false,
            'sidecar_exec_binary' => '/opt/swoolefy/bin/rocketmq-sidecar',
            'sidecar_args' => [],
            'sidecar_socket_path' => '',
            'sidecar_plugins' => 'rocketmq',
            'sidecar_rocketmq_proxy_endpoint' => '',
            'sidecar_rocketmq_access_key' => '',
            'sidecar_rocketmq_secret_key' => '',
            'sidecar_rocketmq_allowed_topics' => '',
        ];
        foreach (SidecarConfig::publicSettings() as $key => $value) {
            $defaults[$key] = $value;
        }
        foreach ($defaults as $key => $value) {
            if (!array_key_exists($key, $row)) {
                $row[$key] = $value;
            }
        }

        return $row;
    }

    private static function parseEnable(mixed $value, string $name): bool
    {
        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($parsed === null) {
            throw new SidecarException(self::label($name, 'sidecar_enable') . ' is invalid');
        }

        return $parsed;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function requireName(array $row): string
    {
        $name = $row['sidecar_name'] ?? null;
        if (!is_string($name) || !preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $name)) {
            throw new SidecarException('sidecar_name is invalid');
        }

        return $name;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function requireBinary(array $row, string $name): string
    {
        $binary = $row['sidecar_exec_binary'] ?? null;
        if (!is_string($binary) || !str_starts_with($binary, '/') || str_contains($binary, "\0") || !is_file($binary) || !is_executable($binary)) {
            throw new SidecarException(self::label($name, 'sidecar_exec_binary') . ' must be an absolute executable file');
        }

        return $binary;
    }

    /**
     * @param array<string, mixed> $row
     * @return list<string>
     */
    private static function requireArgs(array $row, string $name): array
    {
        $args = $row['sidecar_args'] ?? null;
        if (!is_array($args) || !array_is_list($args)) {
            throw new SidecarException(self::label($name, 'sidecar_args') . ' must be a list of strings');
        }
        foreach ($args as $arg) {
            if (!is_string($arg) || str_contains($arg, "\0")) {
                throw new SidecarException(self::label($name, 'sidecar_args') . ' must be a list of strings');
            }
        }

        return $args;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function requireSocket(array $row, string $name): string
    {
        $path = $row['sidecar_socket_path'] ?? null;
        if (!is_string($path) || str_contains($path, "\0")) {
            throw new SidecarException(self::label($name, 'sidecar_socket_path') . ' is invalid');
        }
        if ($path !== '' && !str_starts_with($path, '/')) {
            throw new SidecarException(self::label($name, 'sidecar_socket_path') . ' must be an absolute path');
        }

        return $path;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function requireInt(array $row, string $key, int $min, int $max, string $name): int
    {
        $value = $row[$key] ?? null;
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new SidecarException(self::label($name, $key) . ' out of range');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function requirePlugins(array $row, string $name): string
    {
        $plugins = $row['sidecar_plugins'] ?? null;
        if (!is_string($plugins) || !preg_match('/^[a-z0-9]+(,[a-z0-9]+)*$/', $plugins)) {
            throw new SidecarException(self::label($name, 'sidecar_plugins') . ' is invalid');
        }
        $parts = explode(',', $plugins);
        if (count($parts) !== count(array_unique($parts))) {
            throw new SidecarException(self::label($name, 'sidecar_plugins') . ' has duplicates');
        }

        return $plugins;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function requireString(array $row, string $key, string $name): string
    {
        $value = $row[$key] ?? null;
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
