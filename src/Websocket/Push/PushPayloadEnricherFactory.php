<?php

namespace Swoolefy\Websocket\Push;

use Swoolefy\Websocket\Cluster\ClusterConfig;

/**
 * 从 Config/websocket.php → push.enricher 加载载荷扩展器。
 *
 * ## 配置示例（Config/websocket.php）
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
    private static ?PushPayloadEnricherInterface $enricher = null;
    private static bool $resolved = false;
    private static ?PushPayloadEnricherInterface $override = null;

    /**
     * 单测或脚本注入 enricher，优先级高于配置文件。
     * 传入 null 表示本次进程内不使用 enricher。
     */
    public static function setOverride(?PushPayloadEnricherInterface $enricher): void
    {
        self::$override = $enricher;
        self::$resolved = true;
        self::$enricher = $enricher;
    }

    /** 重置缓存，供单测 teardown 使用 */
    public static function reset(): void
    {
        self::$enricher = null;
        self::$resolved = false;
        self::$override = null;
    }

    /**
     * 获取 enricher 单例（进程内只解析一次配置）。
     *
     * @return PushPayloadEnricherInterface|null 未配置或解析失败时返回 null
     */
    public static function get(): ?PushPayloadEnricherInterface
    {
        if (self::$resolved) {
            return self::$enricher;
        }

        self::$resolved = true;
        if (self::$override instanceof PushPayloadEnricherInterface) {
            self::$enricher = self::$override;

            return self::$enricher;
        }

        $config = ClusterConfig::pushSettings()['enricher'] ?? null;
        self::$enricher = self::resolve($config);

        return self::$enricher;
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

        // [Class, 'method']：PHP 中实例方法不能直接 is_callable([Class, 'method'])，需先 new 再绑定
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
}
