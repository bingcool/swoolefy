<?php

use Swoolefy\Core\SystemEnv;
use Swoolefy\Core\Schedule\ScheduleEvent;
use Swoolefy\Worker\Cron\CronForkProcess;

return [
//    [
//        'cron_name' => "shell1",
//        'cron_expression' => 5,
//        'exec_bin_file' => "/bin/bash",
//        'exec_script' => APP_PATH.'/Python/shell.sh',
//        'with_block_lapping' => true,
//        'output' => '/dev/null',
//        'extend' => [],
//        'description' => '',
//        // 在某些时间段执行
//        "cron_between" => [
//            ['00:01', "12:00"]
//        ],
//        // 跳过某些时间段执行
//        'cron_skip' => [
//            ["14:00","18:00"]
//        ],
//
//        'fork_type' => CronForkProcess::FORK_TYPE_EXEC,
//        'fork_success_callback' => function(\Swoolefy\Core\Schedule\ScheduleEvent $event) {
//            var_dump($event->cron_name);
//        },
//    ],
    [
        'cron_name' => "swoolefy-php",
        'cron_expression' => 5,
        'exec_bin_file' => SystemEnv::PhpBinFile(),
        'exec_script' => '/home/wwwroot/swoolefy/script.php start '.APP_NAME.' --c=test:script',
        'with_block_lapping' => true,
        'output' => '/dev/null',
        'argv'   => [],
        'call_fns' => [[\Swoolefy\Core\Schedule\DynamicCallFn::class, 'generatePidFile']],
        'extend' => [
            'schedule_model' => 'cron',
        ],
        'description' => '',
        'fork_type' => CronForkProcess::FORK_TYPE_EXEC,
    ]
];