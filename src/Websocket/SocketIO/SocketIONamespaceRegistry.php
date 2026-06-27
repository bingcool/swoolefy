<?php

namespace Swoolefy\Websocket\SocketIO;

use Swoolefy\Websocket\WebsocketConnectionManager;

/**
 * 连接级 Socket.IO namespace 注册表。
 *
 * 存储：`WebsocketConnectionManager` 连接表字段 `socketio_namespaces`（JSON 数组）
 *
 * ## 路由优先级（resolveEndpoint）
 *
 * 1. `namespaces.{ns}.event_routes`
 * 2. 全局 `event_routes`
 * 3. 约定：`/admin` + `chat.send` → `admin/chat/send`
 *
 * ## Push namespace
 *
 * data 中带 `namespace` 或 `_socketio.namespace` 指定出站 ns。
 */
class SocketIONamespaceRegistry
{
    /** @var array<string, array<string, mixed>> 单测注入 */
    private static array $configOverride = [];

    public static function setConfigOverride(array $config): void
    {
        self::$configOverride = $config;
    }

    public static function resetForTest(): void
    {
        self::$configOverride = [];
    }

    /** 白名单：`['*']` 允许任意 `/xxx`；否则必须在列表内 */
    public static function isAllowed(string $namespace, array $config): bool
    {
        $socketio = self::socketioConfig($config);
        $allowed = $socketio['allowed_namespaces'] ?? ['*'];
        if (!is_array($allowed)) {
            return $namespace === '/';
        }
        if (in_array('*', $allowed, true)) {
            return $namespace !== '' && str_starts_with($namespace, '/');
        }

        return in_array($namespace, $allowed, true);
    }

    /**
     * 客户端 `40/ns,` 时调用；写入连接表 socketio_namespaces。
     *
     * @return string|null 错误原因；null 表示成功
     */
    public static function connectNamespace(int $fd, string $namespace, array $config): ?string
    {
        $namespace = self::normalizeNamespace($namespace);
        if ($namespace === null) {
            return 'invalid namespace';
        }
        if (!self::isAllowed($namespace, $config)) {
            return 'namespace not allowed';
        }

        $connection = WebsocketConnectionManager::getConnection($fd);
        if (!$connection) {
            return 'connection not found';
        }

        $namespaces = self::decode((string) ($connection['socketio_namespaces'] ?? ''));
        if (!in_array($namespace, $namespaces, true)) {
            $namespaces[] = $namespace;
            $connection['socketio_namespaces'] = self::encode($namespaces);
            WebsocketConnectionManager::updateConnection($fd, $connection);
        }

        return null;
    }

    /** @return bool true 表示已无 namespace，可关闭 Engine 连接 */
    public static function disconnectNamespace(int $fd, string $namespace): bool
    {
        $namespace = self::normalizeNamespace($namespace) ?? '/';
        $connection = WebsocketConnectionManager::getConnection($fd);
        if (!$connection) {
            return true;
        }

        $namespaces = array_values(array_filter(
            self::decode((string) ($connection['socketio_namespaces'] ?? '')),
            static fn (string $item): bool => $item !== $namespace
        ));
        $connection['socketio_namespaces'] = self::encode($namespaces);
        WebsocketConnectionManager::updateConnection($fd, $connection);

        return $namespaces === [];
    }

    public static function isConnected(int $fd, string $namespace): bool
    {
        $namespace = self::normalizeNamespace($namespace) ?? '/';
        $connection = WebsocketConnectionManager::getConnection($fd);

        return in_array($namespace, self::decode((string) ($connection['socketio_namespaces'] ?? '')), true);
    }

    /** @return string[] */
    public static function connectedNamespaces(int $fd): array
    {
        $connection = WebsocketConnectionManager::getConnection($fd);
        if (!$connection) {
            return [];
        }

        return self::decode((string) ($connection['socketio_namespaces'] ?? ''));
    }

    public static function resolvePushNamespace(array $data): string
    {
        if (isset($data['_socketio']['namespace']) && is_string($data['_socketio']['namespace'])) {
            return self::normalizeNamespace($data['_socketio']['namespace']) ?? '/';
        }
        if (isset($data['namespace']) && is_string($data['namespace'])) {
            return self::normalizeNamespace($data['namespace']) ?? '/';
        }

        return '/';
    }

    public static function resolveEndpoint(string $event, string $namespace, array $config): string
    {
        $socketio = self::socketioConfig($config);
        $namespace = self::normalizeNamespace($namespace) ?? '/';

        $nsRoutes = $socketio['namespaces'][$namespace]['event_routes'] ?? [];
        if (is_array($nsRoutes) && isset($nsRoutes[$event])) {
            return trim((string) $nsRoutes[$event], '/');
        }

        $routes = $socketio['event_routes'] ?? [];
        if (is_array($routes) && isset($routes[$event])) {
            return trim((string) $routes[$event], '/');
        }

        $eventPath = trim(str_replace('.', '/', $event), '/');
        if ($namespace !== '/') {
            return trim(trim($namespace, '/') . '/' . $eventPath, '/');
        }

        return $eventPath;
    }

    private static function socketioConfig(array $config): array
    {
        if (self::$configOverride !== []) {
            return self::$configOverride;
        }

        $socketio = $config['socketio'] ?? [];

        return is_array($socketio) ? $socketio : [];
    }

    private static function normalizeNamespace(string $namespace): ?string
    {
        $namespace = trim($namespace);
        if ($namespace === '') {
            return '/';
        }
        if (!str_starts_with($namespace, '/')) {
            $namespace = '/' . $namespace;
        }

        return $namespace;
    }

    /** @return string[] */
    private static function decode(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        $items = json_decode($raw, true);

        return is_array($items)
            ? array_values(array_filter($items, static fn ($item): bool => is_string($item) && $item !== ''))
            : [];
    }

    /** @param string[] $namespaces */
    private static function encode(array $namespaces): string
    {
        return json_encode(array_values(array_unique($namespaces)), JSON_UNESCAPED_UNICODE) ?: '[]';
    }
}
