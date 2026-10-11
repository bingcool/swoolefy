<?php

declare(strict_types=1);

namespace Swoolefy\Support\Queue;

use Swoolefy\Core\Application;

/**
 * 按组件名取出 Config/component/queue.php 里已经建好的队列实例。
 *
 * $name 是组件别名，例如 queue、delayQueue。它不是 Redis 键。
 * Redis 客户端和键名写在 queue.php 的闭包里，这里不再连接 Redis。
 * Application::get() 返回的是组件包装对象，push / pop / retry / getRedis 会转到真实实例。
 */
final class QueueComponent
{
    public static function get(string $name): object
    {
        if ($name === '') {
            throw new QueueException('queue_component is required');
        }
        $app = Application::getApp();
        if (!is_object($app)) {
            throw new QueueException('queue component requires Application');
        }
        $component = $app->get($name);
        if (!is_object($component)) {
            throw new QueueException('queue component ' . $name . ' is missing');
        }

        return $component;
    }
}
