<?php

namespace Swoolefy\Websocket\Offline;

use Swoolefy\Websocket\Cluster\ClusterConfig;

/**
 * 从 Config/websocket.php → offline.on_reconnect 加载上线钩子。
 *
 * 解析规则同 {@see OfflineMessageStoreFactory}。
 * 未配置时 onUserOnline 仅执行框架补推，不回调业务。
 */
class OfflineReconnectHookFactory
{
    private static ?OfflineReconnectHookInterface $hook = null;
    private static bool $resolved = false;
    private static ?OfflineReconnectHookInterface $override = null;

    public static function setOverride(?OfflineReconnectHookInterface $hook): void
    {
        self::$override = $hook;
        self::$resolved = true;
        self::$hook = $hook;
    }

    public static function reset(): void
    {
        self::$hook = null;
        self::$resolved = false;
        self::$override = null;
    }

    public static function get(): ?OfflineReconnectHookInterface
    {
        if (self::$resolved) {
            return self::$hook;
        }

        self::$resolved = true;
        if (self::$override instanceof OfflineReconnectHookInterface) {
            self::$hook = self::$override;

            return self::$hook;
        }

        $config = ClusterConfig::offlineSettings()['on_reconnect'] ?? null;
        self::$hook = self::resolve($config);

        return self::$hook;
    }

    /** @param mixed $config */
    private static function resolve($config): ?OfflineReconnectHookInterface
    {
        if ($config === null || $config === '') {
            return null;
        }

        if ($config instanceof OfflineReconnectHookInterface) {
            return $config;
        }

        if (is_callable($config)) {
            return new CallableOfflineReconnectHook($config);
        }

        if (is_array($config) && count($config) === 2 && is_string($config[0]) && is_string($config[1]) && class_exists($config[0])) {
            $instance = new $config[0]();
            if (method_exists($instance, $config[1])) {
                return new CallableOfflineReconnectHook([$instance, $config[1]]);
            }
        }

        if (is_string($config) && class_exists($config)) {
            $instance = new $config();
            if ($instance instanceof OfflineReconnectHookInterface) {
                return $instance;
            }
            if (is_callable($instance)) {
                return new CallableOfflineReconnectHook($instance);
            }
        }

        return null;
    }
}
