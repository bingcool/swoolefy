<?php
// 应用配置
$dc = include 'dc-test.php';

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
        // 用户行为记录的日志
        'log' => function($name) {
            if(IS_WORKER_SERVICE) {
                $logger = new \Swoolefy\Util\Log($name);
                $logger->setChannel('application');
                $logger->setLogFilePath(LOG_PATH.'/worker.log');
                return $logger;
            }else {
                $logger = new \Swoolefy\Util\Log($name);
                $logger->setChannel('application');
                $logger->setLogFilePath(LOG_PATH.'/runtime.log');
                return $logger;
            }
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
                'scheme' => $dc['predis']['scheme'],
                'host'   => $dc['predis']['host'],
                'port'   => $dc['predis']['port'],
            ]);
            return $predis;
        },

    ],

//    'catch_handle' => function(\Swoole\Http\Request $request, \Swoole\Http\Response $response) {
//        $response->end(json_encode(['code'=>-1,'msg'=>'系统维护中']));
//    }
];
