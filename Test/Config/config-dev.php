<?php
// 应用配置
$dc = include 'dc-dev.php';

return [

    // db|redis连接池
    'enable_component_pools' => [
        'db' => [
            'pools_num' => 5,
            'push_timeout' => 2,
            'pop_timeout' => 1,
            'live_time' => 10
        ],

        'redis' => [
            'pools_num' => 5,
            'push_timeout' => 2,
            'pop_timeout' => 1,
            'live_time' => 10
        ]
    ],

    // 组件
    'components' => [
        // logger
        'log' => function() {
            $logger = new \Swoolefy\Util\Log();
            $logger->setChannel('application');
            $logger->setLogFilePath('/tmp/Test/runtime.log');
            return $logger;
        },

        'db' => function() use($dc) {
            $db = new \Common\Library\Db\Mysql($dc['mysql_db']);
            return $db;
        },

        'redis' => function() use($dc) {
            $redis = new \Common\Library\Cache\Redis();
            $redis->connect($dc['redis']['host'], $dc['redis']['port']);
            return $redis;
        },

        'predis' => function() use($dc) {
            $predis = new \Common\Library\Cache\predis([
                'scheme' => $dc['predis']['scheme'],
                'host'   => $dc['predis']['host'],
                'port'   => $dc['predis']['port'],
            ]);
            return $predis;
        },

    ]
];
