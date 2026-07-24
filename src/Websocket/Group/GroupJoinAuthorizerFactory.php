<?php

namespace Swoolefy\Websocket\Group;

use Swoolefy\Websocket\Cluster\ClusterConfig;

/**
 * 从 Config/websocket.php → group.join_authorizer 加载加组鉴权器。
 *
 * ## 协程安全
 *
 * - **只缓存配置**，每次 {@see get()} / {@see authorize()} 新建业务实例。
 * - Authorizer 必须无状态：禁止 `$this->currentUser` 等请求态成员；只用方法参数。
 * - {@see setOverride()} 供单测注入并复用同一实例。
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
    private static bool $configLoaded = false;

    /** @var mixed */
    private static $rawConfig = null;

    private static bool $hasOverride = false;

    private static ?GroupJoinAuthorizerInterface $override = null;

    /** 单测注入鉴权器，优先级高于配置文件 */
    public static function setOverride(?GroupJoinAuthorizerInterface $authorizer): void
    {
        self::$hasOverride = true;
        self::$override = $authorizer;
    }

    /** 清空配置缓存与 override（单测 teardown） */
    public static function reset(): void
    {
        self::$configLoaded = false;
        self::$rawConfig = null;
        self::$hasOverride = false;
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

    /**
     * 获取鉴权器（配置只读一次；业务实例每次新建）。
     */
    public static function get(): ?GroupJoinAuthorizerInterface
    {
        if (self::$hasOverride) {
            return self::$override;
        }

        self::loadConfigOnce();

        return self::resolve(self::$rawConfig);
    }

    /**
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

    private static function loadConfigOnce(): void
    {
        if (self::$configLoaded) {
            return;
        }

        self::$configLoaded = true;
        self::$rawConfig = ClusterConfig::groupSettings()['join_authorizer'] ?? null;
    }
}
