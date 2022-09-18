<?php
return [
    // 独立进程本地处理任务
    [
        'process_name' => 'test-local-cron-worker',
        'handler' => \Swoolefy\Worker\Cron\CronLocalProcess::class,
        'worker_num' => 1, // 默认动态进程数量
        'max_handle' => 100, //消费达到10000后reboot进程
        'life_time'  => 130, // 每隔3600s重启进程
        'limit_run_coroutine_num' => 10, //当前进程的实时协程数量，如果协程数量超过此设置的数量，则禁止继续消费队列处理业务，而是在等待
        'extend_data' => [],
        'args' => [
            'cron_name' => 'cancel order', // 取消订单
            'handler_class' => \Test\WorkerCron\LocalOrder\LocalOrderHandle::class, //处理类
            //'cron_expression' => '*/1 * * * *', // 每分钟执行一次

            'cron_expression' => 10, // 每分钟执行一次
        ],
    ],


    // 定时fork进程处理任务
    [
        'process_name' => 'worker-fork-task-cron',
        'handler' => \Swoolefy\Worker\Cron\CronForkProcess::class,
        'worker_num' => 1, // 默认动态进程数量
        'max_handle' => 100, //消费达到10000后reboot进程
        'life_time'  => 3600, // 每隔3600s重启进程
        'limit_run_coroutine_num' => 10, // 当前进程的实时协程数量，如果协程数量超过此设置的数量，则禁止继续消费队列处理业务，而是在等待
        'extend_data' => [],
        'args' => [
            // 定时任务列表
            'task_list' => [
                // fork task
                [
                    'cron_name' => 'send message', // 发送短信
                    'run_cli' => 'php /home/wwwroot/swoolefy/Test/WorkerCron/ForkOrder/ForkOrderHandle.php', // fork执行的bin命令行
                    //'cron_expression' => 10, // 每分钟执行一次
                    'cron_expression' => '*/1 * * * *', // 每分钟执行一次
                ]
            ]
        ],
    ]
];