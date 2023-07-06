<?php

use PhpAmqpLib\Connection\AMQPConnectionConfig;
use Test\Config\AmqpConfig;
use Swoolefy\Core\SystemEnv;

return [

    'mysql_db' => [
        // 类型
        'type' => 'mysql',
        // 服务器地址
        'hostname'        => '127.0.0.1',
        // 数据库名
        'database'        => 'bingcool',
        // 用户名
        'username'        => 'root',
        // 密码
        'password'        => '123456',
        // 端口
        'hostport'        => '3306',
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
        'scheme' => 'tp',
        'host'   => '127.0.0.1',
        'port'   => 6379,
    ],

    'redis' => [
        'host'   => '127.0.0.1',
        'port'   => 6379,
    ],

    'amqp_connection' => [
        'host_list' => [
            [
                'host' => '172.17.0.1',
                'port' => 5672,
                'user' => 'admin',
                'password' => 'admin',
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

    'kafka_broker_list' => ['127.0.0.1:9092'],
];