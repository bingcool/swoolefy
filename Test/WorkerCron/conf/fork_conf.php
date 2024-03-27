<?php

return // 定时fork进程处理任务
    [
        [
        'process_name' => 'test-fork-task-cron', // 进程名称
        'handler' => \Swoolefy\Worker\Cron\CronForkProcess::class,
        'description' => '发送短信',
        'worker_num' => 1, // 默认动态进程数量
        'max_handle' => 100, //消费达到10000后reboot进程
        'life_time'  => 3600, // 每隔3600s重启进程
        'limit_run_coroutine_num' => 10, // 当前进程的实时协程数量，如果协程数量超过此设置的数量，则禁止继续消费队列处理业务，而是在等待
        'extend_data' => [],
        'args' => [
            // 定时任务列表
            'task_list' => [
                // fork task
//                [
//                    'cron_name' => 'send message', // 发送短信
//                    'exec_bin_file' => 'php', // fork执行的bin命令行
//                    'fork_type' => \Swoolefy\Worker\Cron\CronForkProcess::FORK_TYPE_EXEC,
//                    'exec_script' => '/home/wwwroot/swoolefy/Test/WorkerCron/ForkOrder/ForkOrderHandle.php',
//                    'cron_expression' => 10, // 10s执行一次
//                    'params' => [],
//                    //'cron_expression' => '*/1 * * * *', // 每分钟执行一次
//                ],
                [
                    'cron_name' => 'fixed use data', // 发送短信
                    'exec_bin_file' => '/usr/bin/php ', // fork执行的bin命令行
                    'fork_type' => \Swoolefy\Worker\Cron\CronForkProcess::FORK_TYPE_PROC_OPEN,
                    'exec_script' => 'script.php start test --r=fixed:user:name',
                    'cron_expression' => 10, // 10s执行一次
                    'params' => [],
                    //'cron_expression' => '*/1 * * * *', // 每分钟执行一次
                ],
            ]
        ],
    ],
];