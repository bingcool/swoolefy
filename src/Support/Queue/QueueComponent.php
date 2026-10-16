<?php

declare(strict_types=1);

namespace Swoolefy\Support\Queue;

use Swoolefy\Core\Application;

/**
 * 按组件名取出 Config/component/queue.php 里的 Redis 连接。
 *
 * $name 是组件别名，例如 queue、delayQueue。它不是 Redis 键。
 * queue.php 只返回连接。Queue、RedisDelayQueue 和键由生产者、消费者自己创建。
 * Application::get() 返回包装对象，这里取出里面的连接再交给 Library 的队列类。
 */
final class QueueComponent
{
    public static function get(string $name): object
    {
        if ($name === '') {
            throw new QueueException('queueComponent is required');
        }
        $app = Application::getApp();
        if (!is_object($app)) {
            throw new QueueException('queue component requires Application');
        }
        $component = $app->get($name);
        if (!is_object($component)) {
            throw new QueueException('queue component ' . $name . ' is missing');
        }
        if (method_exists($component, 'getObject')) {
            $redis = $component->getObject();
            if (is_object($redis)) {
                return $redis;
            }
        }

        return $component;
    }
}
