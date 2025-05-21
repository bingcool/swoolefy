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
        // 是否自动参数绑定,默认是true
        'auto_param_bind' => true,
        // 是否支持事务嵌套
        'support_savepoint' => false,
        // sql执行日志条目设置,不能设置太大,适合调试使用,设置为0，则不使用
        'spend_log_limit' => 30,
        // 是否开启dubug
        'debug' => 1
    ],

    'pg_db' => [
        // 类型
        'type' => 'pgsql',
        // 服务器地址
        'hostname'        => env('POSTGRES_HOST','127.0.0.1'),
        // 数据库名
        'database'        => env('POSTGRES_DATABASE'),
        // 用户名
        'username'        => env('POSTGRES_USER'),
        // 密码
        'password'        => env('POSTGRES_PASSWORD'),
        // 端口
        'hostport'        => env('POSTGRES_PORT'),
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
        // 是否自动参数绑定,默认是true
        'auto_param_bind' => true,
        // 是否支持事务嵌套
        'support_savepoint' => false,
        // sql执行日志条目设置,不能设置太大,适合调试使用,设置为0，则不使用
        'spend_log_limit' => 30,
        // 是否开启dubug
        'debug' => 0
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

    'amqp_connection' => [
        'host_list' => [
            [
                'host' => '192.168.23.53',
                'port' => 5672,
//                'user' => 'admin',
//                'password' => 'admin',
                'user' => 'rabbitmq',
                'password' => '123456',
                'vhost' => 'my_vhost'
            ]
        ],
        'options' => [
            'is_lazy' => true, //必须设置true
            'insist' => false,
            'login_method' => 'AMQPLAIN',
            'login_response' => '',
            'locale' => 'en_US',
            'connection_timeout' => 3.0,
            'read_write_timeout' => 3.0,
            'context' => null,
            'keepalive' => true,
            'heartbeat' => 10,
        ]
    ],

    'kafka_broker_list' => ['192.168.23.53:9092'],
];