<?php
// 应用配置
$dc = include 'dc-gra.php';

return [

    'components' => [
        // logger
        'log' => function() {
            $logger = new \Swoolefy\Util\Log();
            $logger->setChannel('application');
            $logger->setLogFilePath('tmp/Test/runtime.log');
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
                'scheme' => $dc['scheme'],
                'host'   => $dc['host'],
                'port'   => $dc['port'],
            ]);
            return $predis;
        },
    ]
];
