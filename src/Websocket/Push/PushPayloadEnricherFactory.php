<?php

namespace Swoolefy\Websocket\Push;

use Swoolefy\Websocket\Cluster\ClusterConfig;

/**
 * 从 Config/websocket.php → push.enricher 加载载荷扩展器。
 *
 * ## 协程安全
 *
 * - **只缓存配置**，每次 {@see get()} 按配置新建业务实例（或 Callable 包装）。
 * - 禁止在 Enricher 成员变量中保存当前 user/fd/request；请求数据只用方法参数。
 * - {@see setOverride()} 供单测注入，进程内复用同一实例（测试可控）。
 *
 * ## 配置示例
 *
 * ```php
 * 'push' => [
 *     'enricher' => [\App\Push\MessagePushEnricher::class, 'enrich'],
 * ],
 * ```
 *
 * 注意：顶层 `push` 与 `cluster.push`（Redis 频道前缀）是不同配置项，勿混淆。
 */
class PushPayloadEnricherFactory
{
    private static bool $configLoaded = false;

    /** @var mixed */
    private static $rawConfig = null;

    private static bool $hasOverride = false;

    private static ?PushPayloadEnricherInterface $override = null;

    /**
     * 单测或脚本注入 enricher，优先级高于配置文件。
     * 传入 null 表示本次进程内不使用 enricher。
     */
    public static function setOverride(?PushPayloadEnricherInterface $enricher): void
    {
        self::$hasOverride = true;
        self::$override = $enricher;
    }

    /** 重置缓存，供单测 teardown 使用 */
    public static function reset(): void
    {
        self::$configLoaded = false;
        self::$rawConfig = null;
        self::$hasOverride = false;
        self::$override = null;
    }

    /**
     * 获取 enricher（配置只读一次；业务实例每次新建，避免请求态串协程）。
     *
     * @return PushPayloadEnricherInterface|null 未配置或解析失败时返回 null
     */
    public static function get(): ?PushPayloadEnricherInterface
    {
        if (self::$hasOverride) {
            return self::$override;
        }

        self::loadConfigOnce();

        return self::resolve(self::$rawConfig);
    }

    /**
     * 将配置项解析为 PushPayloadEnricherInterface。
     *
     * 支持：实例对象 | callable | [Class, method] | 类名（实现接口或 __invoke）
     *
     * @param mixed $config
     */
    private static function resolve($config): ?PushPayloadEnricherInterface
    {
        if ($config === null || $config === '') {
            return null;
        }

        if ($config instanceof PushPayloadEnricherInterface) {
            return $config;
        }

        if (is_callable($config)) {
            return new CallablePushPayloadEnricher($config);
        }

        // [Class, 'method']：每次 new Class，避免业务实例上的请求态被多协程复用
        if (is_array($config) && count($config) === 2 && is_string($config[0]) && is_string($config[1]) && class_exists($config[0])) {
            $instance = new $config[0]();
            if (method_exists($instance, $config[1])) {
                return new CallablePushPayloadEnricher([$instance, $config[1]]);
            }
        }

        if (is_string($config) && class_exists($config)) {
            $instance = new $config();
            if ($instance instanceof PushPayloadEnricherInterface) {
                return $instance;
            }
            if (is_callable($instance)) {
                return new CallablePushPayloadEnricher($instance);
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
        self::$rawConfig = ClusterConfig::pushSettings()['enricher'] ?? null;
    }
}
