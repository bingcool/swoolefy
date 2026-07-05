<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow;

use Swoolefy\Core\Application;
use Swoolefy\Library\Redis\RedisConnection;
use Swoolefy\Support\Workflow\Exception\WorkflowException;

/**
 * 从 Swoolefy 组件容器解析 Redis（phpredis / predis 均继承 RedisConnection）。
 */
final class WorkflowRedisResolver
{
    public static function resolve(string $componentName): RedisConnection
    {
        $app = Application::getApp();
        if ($app === null) {
            throw new WorkflowException(
                'Workflow redis run_store requires Swoolefy Application context (HTTP/Worker coroutine).',
            );
        }

        $container = $app->get($componentName);
        if (!is_object($container) || !method_exists($container, 'getObject')) {
            throw new WorkflowException("Workflow redis component [{$componentName}] is not available.");
        }

        $redis = $container->getObject();
        if (!$redis instanceof RedisConnection) {
            throw new WorkflowException(
                "Workflow redis component [{$componentName}] must return " . RedisConnection::class,
            );
        }

        return $redis;
    }
}
