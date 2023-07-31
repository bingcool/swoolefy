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


    // 定时fork进程处理任务
    [
        'process_name' => 'test-fork-task-cron', // 进程名称
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
                    'exec_bin_file' => 'php', // fork执行的bin命令行
                    'fork_type' => \Swoolefy\Worker\Cron\CronForkProcess::FORK_TYPE_EXEC,
                    'exec_script' => '/home/wwwroot/swoolefy/Test/WorkerCron/ForkOrder/ForkOrderHandle.php',
                    'cron_expression' => 10, // 10s执行一次
                    'params' => []
                    //'cron_expression' => '*/1 * * * *', // 每分钟执行一次
                ]
            ]
        ],
    ],

    // 定时请求远程url触发远程url的任务处理
    [
        'process_name' => 'test-url-task-cron', // 进程名称
        'handler' => \Swoolefy\Worker\Cron\CronUrlProcess::class,
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
                    'url'   => 'https://www.baidu.com',
                    'method' => 'get',
                    'connect_time_out' => 10, //连接对方主机最长等待时间
                    'curl_time_out' => 15, // 整个请求最长等待总时间，要比connection_time_out大
                    'options' => [], // curl option
                    'headers' => [], // 请求头
                    'params' => [], // post参数
//                    'callback' => function(RawResponse $response) {
//                        (new \Test\WorkerCron\CurlQuery\RemoteUrl())->handle($response);
//                    },
                    'callback' => [\Test\WorkerCron\CurlQuery\RemoteUrl::class, 'handle'],
                    'cron_expression' => 3, // 10s执行一次
                    //'cron_expression' => '*/1 * * * *', // 每分钟执行一次
                ]
            ]
        ],
    ]
];