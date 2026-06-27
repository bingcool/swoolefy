<?php

namespace Swoolefy\Websocket\Offline;

use Swoolefy\Websocket\Cluster\ClusterConfig;

/**
 * 从 Config/websocket.php → offline.store 加载离线消息存储。
 *
 * 解析规则与 `PushPayloadEnricherFactory` / `GroupJoinAuthorizerFactory` 一致：
 * - 实例对象
 * - callable / 闭包（返回 Store 实例）
 * - `[Class, 'method']`（工厂方法）
 * - 实现 OfflineMessageStoreInterface 的类名
 *
 * @see ClusterConfig::offlineSettings()
 */
class OfflineMessageStoreFactory
{
    private static ?OfflineMessageStoreInterface $store = null;
    private static bool $resolved = false;
    private static ?OfflineMessageStoreInterface $override = null;

    /** 单测注入，优先级高于配置文件 */
    public static function setOverride(?OfflineMessageStoreInterface $store): void
    {
        self::$override = $store;
        self::$resolved = true;
        self::$store = $store;
    }

    public static function reset(): void
    {
        self::$store = null;
        self::$resolved = false;
        self::$override = null;
    }

    /** 进程内单例；未配置或解析失败返回 null */
    public static function get(): ?OfflineMessageStoreInterface
    {
        if (self::$resolved) {
            return self::$store;
        }

        self::$resolved = true;
        if (self::$override instanceof OfflineMessageStoreInterface) {
            self::$store = self::$override;

            return self::$store;
        }

        $config = ClusterConfig::offlineSettings()['store'] ?? null;
        self::$store = self::resolve($config);

        return self::$store;
    }

    /** @param mixed $config */
    private static function resolve($config): ?OfflineMessageStoreInterface
    {
        if ($config === null || $config === '') {
            return null;
        }

        if ($config instanceof OfflineMessageStoreInterface) {
            return $config;
        }

        if (is_callable($config)) {
            $instance = $config();

            return $instance instanceof OfflineMessageStoreInterface ? $instance : null;
        }

        if (is_array($config) && count($config) === 2 && is_string($config[0]) && is_string($config[1]) && class_exists($config[0])) {
            $instance = new $config[0]();
            if (method_exists($instance, $config[1])) {
                $result = $instance->{$config[1]}();

                return $result instanceof OfflineMessageStoreInterface ? $result : null;
            }
        }

        if (is_string($config) && class_exists($config)) {
            $instance = new $config();
            if ($instance instanceof OfflineMessageStoreInterface) {
                return $instance;
            }
        }

        return null;
    }
}
