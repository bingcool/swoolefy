<?php

use Swoolefy\Core\SystemEnv;

return [

    'mysql_db' => [
        // 类型
        'type' => 'mysql',
        // 服务器地址
        'hostname'        => env('DB_HOST_NAME','127.0.0.1'),
        // 数据库名
        'database'        => env('DB_HOST_DATABASE'),
        // 用户名
        'username'        => env('DB_USER_NAME'),
        // 密码
        'password'        => env('DB_PASSWORD'),
        // 端口
        'hostport'        => env('DB_HOST_PORT'),
        // 连接dsn
        'dsn'             => '',
        // 数据库连接参数
        'params'          => [],
        // 数据库编码默认采用utf8
        'charset'         => 'utf8mb4',
        // 数据库表前缀
        'prefix'          => '',
        // fetchType
        'fetch_type' => \PDO::FETCH_ASSOC,
        // 是否需要断线重连
        'break_reconnect' => true,
        // 是否支持事务嵌套
        'support_savepoint' => false,
        // sql执行日志条目设置,不能设置太大,适合调试使用,设置为0，则不使用
        'spend_log_limit' => 30,
        // 是否开启dubug
        'debug' => SystemEnv::isPrdEnv() ? 0 : 1
    ],

    'predis' => [
        'scheme' => 'tcp',
        'host'   => env('REDIS_HOST'),
        'port'   => env('REDIS_PORT'),
    ],

    'redis' => [
        'host'   => env('REDIS_HOST'),
        'port'   => env('REDIS_PORT'),
    ],

    /**
     * RabbitMQ 连接参数，供 Config/component/amqp/connection.php 的 amqpConnection 使用。
     * 账号与地址优先读 .env：AMQP_HOST、AMQP_PORT、AMQP_USER、AMQP_PASSWORD、AMQP_VHOST。
     * host_list 可配置多节点；options.is_lazy 必须为 true（协程懒连接）。
     */
    'amqp_connection' => [
        'host_list' => [
            [
                'host' => env('AMQP_HOST', '127.0.0.1'),
                'port' => (int) env('AMQP_PORT', 5672),
                'user' => env('AMQP_USER', 'guest'),
                'password' => env('AMQP_PASSWORD', 'guest'),
                'vhost' => env('AMQP_VHOST', '/'),
            ],
        ],
        'options' => [
            'is_lazy' => true, // 必须为 true：协程环境下延迟到首次使用时再连接
            'insist' => false,
            'login_method' => 'AMQPLAIN',
            'login_response' => '',
            'locale' => 'en_US',
            'connection_timeout' => 3.0, // 建连超时（秒）
            'read_write_timeout' => 3.0, // 读写超时（秒），应大于 heartbeat
            'context' => null,
            'keepalive' => true,
            'heartbeat' => 10, // 心跳间隔（秒）
        ],
    ],

    /**
     * Kafka broker 列表，供 component/kafka 的 Producer / Consumer 使用。
     * .env KAFKA_BROKER_LIST 为逗号分隔，如 127.0.0.1:9092,127.0.0.1:9093。
     * Topic 与 group.id 不写在这里，写在各 producer/consumer 文件的局部变量中。
     */
    'kafka_broker_list' => array_values(array_filter(array_map(
        static fn (string $broker): string => trim($broker),
        explode(',', (string) env('KAFKA_BROKER_LIST', '127.0.0.1:9092')),
    ))),
];