<?php

return [
    [
        // 与 Test\Process\Rocketmq\ConsumerRocketmq 同一 Topic、消费组、Tag。同一个消费组 + Topic 的 worker_num 保持 1。
        'process_name' => 'rocketmq-consumer-worker',
        'handler' => \Test\WorkerDaemon\Rocketmq\ConsumerRocketmq::class,
        'worker_num' => 1,
        'max_handle' => 10000,
        'life_time'  => 3600,
        'limit_run_coroutine_num' => 10,
        'extend_data' => [],
        'args' => [],
    ],
];
