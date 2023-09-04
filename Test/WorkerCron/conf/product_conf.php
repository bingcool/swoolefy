<?php

return [
    // 独立进程本地处理任务
    [
        'process_name' => 'test-local-cron-worker', // 进程名称
        'handler' => \Swoolefy\Worker\Cron\CronLocalProcess::class,
        'worker_num' => 1, // 默认动态进程数量
        'max_handle' => 100, //消费达到10000后reboot进程
        'life_time'  => 130, // 每隔3600s重启进程
        'limit_run_coroutine_num' => 10, //当前进程的实时协程数量，如果协程数量超过此设置的数量，则禁止继续消费队列处理业务，而是在等待
        'extend_data' => [],
        'args' => [
            'cron_name' => 'cancel-order', // 定时任务名称
            'handler_class' => \Test\WorkerCron\LocalOrder\LocalOrderHandle::class, //处理类
            //'cron_expression' => '*/1 * * * *', // 每分钟执行一次
            'cron_expression' => 10, // 10s执行一次
        ],
    ],
];