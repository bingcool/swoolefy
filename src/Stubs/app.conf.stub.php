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
            // 连接池耗尽时可以降级，但降级连接必须纳入总并发连接预算，不能绕过限制量保护
            // 连接池降级创建实例同时最大在线实例，防止降级后高并发下大量创建
            'fallback' => [
                'enabled' => true,
                // 建议设置为max_pool_num的2-3倍
                'max_concurrent' => 5,
            ],
        ],

        'cache' => [
            'max_pool_num' => 5,
            'max_push_timeout' => 2,
            'max_pop_timeout' => 1,
            'max_life_timeout' => 10,
            'enable_tick_clear_pool' => 0,
            // 连接池耗尽时可以降级，但降级连接必须纳入总并发连接预算，不能绕过限制量保护
            // 连接池降级创建实例同时最大在线实例，防止降级后高并发下大量创建
            'fallback' => [
                'enabled' => true,
                // 建议设置为max_pool_num的2-3倍
                'max_concurrent' => 10,
            ],
        ]
    ],

    // 默认DB组件
    'default_db' => 'db',

    // 组件
    'components' => \Swoolefy\Core\SystemEnv::loadComponents()
];
