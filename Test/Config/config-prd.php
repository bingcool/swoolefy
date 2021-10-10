<?php
// 应用配置
$dc = include 'dc-prd.php';

return [

    'components' => [
        // 用户行为记录的日志
        'log' => function($name) {
            $logger = new \Swoolefy\Util\Log($name);
            $logger->setChannel('application');
            $logger->setLogFilePath(LOG_PATH.'/runtime.log');
            return $logger;
        },

        // 系统捕捉异常错误日志
        'error_log' => function($name) {
            $logger = new \Swoolefy\Util\Log($name);
            $logger->setChannel('application');
            $logger->setLogFilePath(LOG_PATH.'/error.log');
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
