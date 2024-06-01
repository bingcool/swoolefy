<?php

return [
    [
        'process_name' => 'test-pipe-worker',
        'handler' => \Test\WorkerDaemon\PipeWorkerProcess::class,
        'worker_num' => 1, // 默认动态进程数量
        'max_handle' => 100, //消费达到10000后reboot进程
        'life_time'  => '* * * * *', // 每隔3600s重启进程
        'limit_run_coroutine_num' => 10, //当前进程的实时协程数量，如果协程数量超过此设置的数量，则禁止继续消费队列处理业务，而是在等待
        'extend_data' => [],
        'args' => []
    ],
//    [
//        'process_name' => 'tick-pipe-worker-test',
//        'handler' => \Test\WorkerDaemon\PipeTestWorkerProcess::class,
//        'worker_num' => 3, // 默认动态进程数量
//        'max_handle' => 100, //消费达到10000后reboot进程
//        'life_time'  => 3600, // 每隔3600s重启进程
//        'limit_run_coroutine_num' => 10, //当前进程的实时协程数量，如果协程数量超过此设置的数量，则禁止继续消费队列处理业务，而是在等待
//        'extend_data' => [],
//        'args' => []
//    ],
];