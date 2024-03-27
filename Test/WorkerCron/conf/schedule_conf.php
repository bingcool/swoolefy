<?php

return // 定时fork进程处理任务
    [
        [
        'process_name' => '系统fork模式任务调度', // 进程名称
        'handler' => \Swoolefy\Worker\Cron\CronForkProcess::class,
        'description' => '系统fork模式任务调度',
        'worker_num' => 1, // 默认动态进程数量
        'max_handle' => 100, //消费达到10000后reboot进程
        'life_time'  => 3600, // 每隔3600s重启进程
        'limit_run_coroutine_num' => 10, // 当前进程的实时协程数量，如果协程数量超过此设置的数量，则禁止继续消费队列处理业务，而是在等待
        'extend_data' => [],
        'args' => [
            // 定时任务列表
            'task_list' => \Test\Scripts\Kernel::buildScheduleTaskList()
        ],
    ],
];