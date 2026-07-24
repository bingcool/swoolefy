<?php

namespace Swoolefy\Websocket\Offline;

use Swoolefy\Websocket\Cluster\ClusterConfig;

/**
 * 从 Config/websocket.php → offline.on_reconnect 加载上线钩子。
 *
 * ## 与 {@see OfflineMessageStoreFactory} 的区别
 *
 * | | Store | 本类（ReconnectHook） |
 * |--|--|--|
 * | 配置键 | `offline.store` | `offline.on_reconnect` |
 * | 产出 | {@see OfflineMessageStoreInterface} | {@see OfflineReconnectHookInterface} |
 * | 职责 | **持久化**：离线消息的存 / 查 / ACK | **上线回调**：补推之后的业务逻辑 |
 * | 何时用 | 落库、补推、`pullPending`、`ackDelivered` | {@see OfflineMessageCoordinator::onUserOnline()} 内，补推之后 |
 * | 是否必须 | `enable=true` 时必须能解析，否则离线能力不算开启 | 可选；不配则只做框架补推 |
 *
 * 一句话：Store 管「消息存在哪、怎么读写」；本类管「人上线了业务还要做什么」
 * （未读 badge、会话同步、审计日志，或 `replay_on_reconnect=false` 时自管补推）。
 *
 * ## 协程安全
 *
 * - **只缓存配置**，每次 {@see get()} 新建 Hook / Callable 包装，避免请求态成员串协程。
 * - Hook 实现保持无状态；用户身份用参数 `$userId` / {@see \Swoolefy\Support\FrameworkContext}。
 * - {@see setOverride()} 供单测注入并复用同一实例。
 *
 * ## 解析规则
 *
 * 与 Store 工厂类似：实例、`[Class, 'method']`、callable / 闭包、实现接口的类名。
 * 未配置时 {@see OfflineMessageCoordinator::onUserOnline()} 仅执行框架补推，不回调业务。
 *
 * @see OfflineReconnectHookInterface
 * @see CallableOfflineReconnectHook
 * @see OfflineMessageCoordinator::onUserOnline()
 */
class OfflineReconnectHookFactory
{
    private static bool $configLoaded = false;

    /** @var mixed */
    private static $rawConfig = null;

    private static bool $hasOverride = false;

    private static ?OfflineReconnectHookInterface $override = null;

    public static function setOverride(?OfflineReconnectHookInterface $hook): void
    {
        self::$hasOverride = true;
        self::$override = $hook;
    }

    public static function reset(): void
    {
        self::$configLoaded = false;
        self::$rawConfig = null;
        self::$hasOverride = false;
        self::$override = null;
    }

    public static function get(): ?OfflineReconnectHookInterface
    {
        if (self::$hasOverride) {
            return self::$override;
        }

        self::loadConfigOnce();

        return self::resolve(self::$rawConfig);
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

    private static function loadConfigOnce(): void
    {
        if (self::$configLoaded) {
            return;
        }

        self::$configLoaded = true;
        self::$rawConfig = ClusterConfig::offlineSettings()['on_reconnect'] ?? null;
    }
}
