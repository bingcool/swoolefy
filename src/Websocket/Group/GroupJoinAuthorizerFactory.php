<?php

namespace Swoolefy\Websocket\Group;

use Swoolefy\Websocket\Cluster\ClusterConfig;

/**
 * 从 Config/websocket.php → group.join_authorizer 加载加组鉴权器。
 *
 * 解析规则与 `PushPayloadEnricherFactory` 一致：
 * - 实例对象
 * - callable / 闭包
 * - `[Class, 'method']`
 * - 实现接口的类名
 *
 * @see GroupJoinAuthorizerInterface
 * @see ClusterConfig::groupSettings()
 */
class GroupJoinAuthorizerFactory
{
    private static ?GroupJoinAuthorizerInterface $authorizer = null;
    private static bool $resolved = false;
    private static ?GroupJoinAuthorizerInterface $override = null;

    /** 单测注入鉴权器，优先级高于配置文件 */
    public static function setOverride(?GroupJoinAuthorizerInterface $authorizer): void
    {
        self::$override = $authorizer;
        self::$resolved = true;
        self::$authorizer = $authorizer;
    }

    /** 清空单例缓存（单测 teardown） */
    public static function reset(): void
    {
        self::$authorizer = null;
        self::$resolved = false;
        self::$override = null;
    }

    /**
     * 执行加组鉴权（joinGroup 入口）。
     *
     * @return string|null null=允许；非空=拒绝原因
     */
    public static function authorize(int $fd, string $userId, string $group, array $params): ?string
    {
        $authorizer = self::get();
        if (!$authorizer instanceof GroupJoinAuthorizerInterface) {
            return null;
        }

        return $authorizer->authorize($fd, $userId, $group, $params);
    }

    /** 获取鉴权器单例（进程内只解析一次配置） */
    public static function get(): ?GroupJoinAuthorizerInterface
    {
        if (self::$resolved) {
            return self::$authorizer;
        }

        self::$resolved = true;
        if (self::$override instanceof GroupJoinAuthorizerInterface) {
            self::$authorizer = self::$override;

            return self::$authorizer;
        }

        $config = ClusterConfig::groupSettings()['join_authorizer'] ?? null;
        self::$authorizer = self::resolve($config);

        return self::$authorizer;
    }

    /**
     * 将配置项解析为 GroupJoinAuthorizerInterface。
     *
     * @param mixed $config
     */
    private static function resolve($config): ?GroupJoinAuthorizerInterface
    {
        if ($config === null || $config === '') {
            return null;
        }

        if ($config instanceof GroupJoinAuthorizerInterface) {
            return $config;
        }

        if (is_callable($config)) {
            return new CallableGroupJoinAuthorizer($config);
        }

        if (is_array($config) && count($config) === 2 && is_string($config[0]) && is_string($config[1]) && class_exists($config[0])) {
            $instance = new $config[0]();
            if (method_exists($instance, $config[1])) {
                return new CallableGroupJoinAuthorizer([$instance, $config[1]]);
            }
        }

        if (is_string($config) && class_exists($config)) {
            $instance = new $config();
            if ($instance instanceof GroupJoinAuthorizerInterface) {
                return $instance;
            }
            if (is_callable($instance)) {
                return new CallableGroupJoinAuthorizer($instance);
            }
        }

        return null;
    }
}
