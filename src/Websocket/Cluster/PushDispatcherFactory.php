<?php

namespace Swoolefy\Websocket\Cluster;

/**
 * 推送分发器工厂：根据 cluster.enable 选择单机或集群实现。
 *
 * 进程内单例缓存，配置热更新场景可调用 reset() 重建。
 *
 * @see PushDispatcherInterface
 */
class PushDispatcherFactory
{
    private static ?PushDispatcherInterface $dispatcher = null;

    /** 获取当前模式下的推送分发器（懒加载单例） */
    public static function get(): PushDispatcherInterface
    {
        if (self::$dispatcher instanceof PushDispatcherInterface) {
            return self::$dispatcher;
        }

        self::$dispatcher = ClusterConfig::isEnabled()
            ? new ClusterPushDispatcher()
            : new LocalPushDispatcher();

        return self::$dispatcher;
    }

    /** 清空单例（单测或配置切换后调用） */
    public static function reset(): void
    {
        self::$dispatcher = null;
    }
}
