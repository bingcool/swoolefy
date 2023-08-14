<?php
/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | @see https://github.com/bingcool/swoolefy
 * +----------------------------------------------------------------------
 */

// 加载环境配置变量
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

        'cache' => [
            'pools_num' => 5,
            'push_timeout' => 2,
            'pop_timeout' => 1,
            'live_time' => 10
        ]
    ],

    // 默认DB组件
    'default_db' => 'db',

    // 组件
    'components' => include 'Component.php',
];
