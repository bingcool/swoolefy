<?php

return [
    // 独立进程本地处理任务
    [
        'process_name' => 'test-local-cron-worker', // 进程名称
        'handler' => \Swoolefy\Worker\Cron\CronLocalProcess::class,
        'worker_num' => 1, // 默认动态进程数量
        'max_handle' => 100, //消费达到10000后reboot进程
        'life_time'  => 3600, // 每隔3600s重启进程
        'limit_run_coroutine_num' => 10, //当前进程的实时协程数量，如果协程数量超过此设置的数量，则禁止继续消费队列处理业务，而是在等待
        'extend_data' => [],
        'args' => [
            'cron_name' => 'cancel-order', // 定时任务名称
            'handler_class' => \Test\WorkerCron\LocalOrder\LocalOrderHandle::class, //处理类
            //'cron_expression' => '*/1 * * * *', // 每分钟执行一次
            'with_block_lapping' => 0, // with_block_lapping = 1,表示每轮任务只能阻塞执行，必须等上一轮任务执行完毕，下一轮才能执行; with_block_lapping = 0, 表示每轮任务时间到了，都可执行,是并发非租塞的
            'run_in_background' => 1, // 后台运行，不受主进程的退出影响传
            'cron_expression' => 10, // 10s执行一次
        ],
    ],

//    // 独立进程本地处理任务
//    [
//        'process_name' => 'test-local-cron-worker11', // 进程名称
//        'handler' => \Swoolefy\Worker\Cron\CronLocalProcess::class,
//        'worker_num' => 2, // 默认动态进程数量
//        'max_handle' => 100, //消费达到10000后reboot进程
//        'life_time'  => 3600, // 每隔3600s重启进程
//        'limit_run_coroutine_num' => 10, //当前进程的实时协程数量，如果协程数量超过此设置的数量，则禁止继续消费队列处理业务，而是在等待
//        'extend_data' => [],
//        'args' => [
//            'cron_name' => 'cancel-order', // 定时任务名称
//            'handler_class' => \Test\WorkerCron\LocalOrder\LocalOrderHandle::class, //处理类
//            //'cron_expression' => '*/1 * * * *', // 每分钟执行一次
//            'cron_expression' => 10, // 10s执行一次
//        ],
//    ],
];