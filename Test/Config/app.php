<?php
// 应用配置

$dc = \Swoolefy\Core\SystemEnv::loadDcEnv();

return [

    // db|redis连接池
    'component_pools' => [
        'db' => [
            'max_pool_num' => 1,
            'max_push_timeout' => 2,
            'max_pop_timeout' => 1,
            'max_life_timeout' => 100,
            'enable_tick_clear_pool' => 0
        ],

        'redis' => [
            'max_pool_num' => 5,
            'max_push_timeout' => 2,
            'max_pop_timeout' => 1,
            'max_life_timeout' => 10,
            'enable_tick_clear_pool' => 0
        ]
    ],

    // default_db
    'default_db' => 'db',

    // 是否启用session组件
    'session_start' => false,

    // 组件
    'components' => \Swoolefy\Core\SystemEnv::loadComponents(),

//    'catch_handle' => function(\Swoole\Http\Request $request, \Swoole\Http\Response $response) {
//        $response->end(json_encode(['code'=>-1,'msg'=>'系统维护中']));
//    }


];
