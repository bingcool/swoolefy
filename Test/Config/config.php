<?php
// 应用配置

$dc = \Swoolefy\Core\SystemEnv::loadDcEnv();

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

    // default_db
    'default_db' => 'db',

    // 组件
    'components' => include 'Component.php'

//    'catch_handle' => function(\Swoole\Http\Request $request, \Swoole\Http\Response $response) {
//        $response->end(json_encode(['code'=>-1,'msg'=>'系统维护中']));
//    }


];
