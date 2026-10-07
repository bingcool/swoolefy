<?php

/**
 * RabbitMQ 连接组件（全局单例协程组件 amqpConnection）。
 *
 * 连接参数来自 Config/dc.php → amqp_connection（host_list、options），
 * 优先读 .env：AMQP_HOST、AMQP_PORT、AMQP_USER、AMQP_PASSWORD、AMQP_VHOST。
 *
 * 业务队列组件内通过 Test\App::getAmqpConnection() 复用本连接。
 */

declare(strict_types=1);

use Swoolefy\Library\Amqp\AmqpStreamConnectionFactory;

$dc = \Swoolefy\Core\SystemEnv::loadDcEnv();

return [
    'amqpConnection' => static function () use ($dc) {
        return AmqpStreamConnectionFactory::create(
            $dc['amqp_connection']['host_list'],
            $dc['amqp_connection']['options'],
        );
    },
];
