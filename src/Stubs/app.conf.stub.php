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
    'component_pools' => [
        'db' => [
            'max_pool_num' => 5,
            'max_push_timeout' => 2,
            'max_pop_timeout' => 1,
            'max_life_timeout' => 10,
            'enable_tick_clear_pool' => 0,
            // 池耗尽后允许降级建连，但降级连接计入当前 Worker inflight，不得绕过 max_pool_num。
            // max_concurrent 省略则为 2*max_pool_num（总上限约 3x）；0 或 enabled=false 则立即 503。
            'fallback' => [
                'enabled' => true,
                'max_concurrent' => 5,
            ],
        ],

        'cache' => [
            'max_pool_num' => 5,
            'max_push_timeout' => 2,
            'max_pop_timeout' => 1,
            'max_life_timeout' => 10,
            'enable_tick_clear_pool' => 0,
            // 池耗尽后允许降级建连，但降级连接计入当前 Worker inflight，不得绕过 max_pool_num。
            // max_concurrent 省略则为 2*max_pool_num（总上限约 3x）；0 或 enabled=false 则立即 503。
            'fallback' => [
                'enabled' => true,
                'max_concurrent' => 10,
            ],
        ]
    ],

    // 默认DB组件
    'default_db' => 'db',

    // 组件
    'components' => \Swoolefy\Core\SystemEnv::loadComponents()
];
