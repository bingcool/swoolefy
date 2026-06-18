<?php

namespace Swoolefy\Websocket\Cluster;

/**
 * 根据 cluster.enable 选择单机或集群推送实现，业务层无感。
 */
class PushDispatcherFactory
{
    private static ?PushDispatcherInterface $dispatcher = null;

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

    public static function reset(): void
    {
        self::$dispatcher = null;
    }
}
