<?php

use Swoolefy\Core\SystemEnv;
use Swoolefy\Core\Schedule\ScheduleEvent;
use Swoolefy\Worker\Cron\CronForkProcess;

return [
    [
        'cron_name' => "shell1",
        'cron_expression' => 15,
        'exec_bin_file' => "/bin/bash",
        'exec_script' => APP_PATH.'/Python/shell.sh',
        'with_block_lapping' => true,
        'output' => '/dev/null',
        'description' => '',
        // 在某些时间段执行
        "cron_between" => [
            ['00:01', "14:00"]
        ],
        // 跳过某些时间段执行
        'cron_skip' => [
            ["14:00","18:00"]
        ],

        'fork_type' => CronForkProcess::FORK_TYPE_EXEC,
        'fork_success_callback' => function(\Swoolefy\Core\Schedule\ScheduleEvent $event) {
            var_dump($event->cron_name." fork success");
        },
    ],
    [
        'cron_name' => "swoolefy-php",
        'run_type'  => \Swoolefy\Worker\Dto\CronForkTaskMetaDto::RUN_TYPE,
        'cron_expression' => 15,
        'exec_bin_file' => SystemEnv::PhpBinFile(),
        'exec_script' => '/home/wwwroot/swoolefy/script.php start '.APP_NAME.' --c=test:script',
        'with_block_lapping' => true,
        'output' => '/dev/null',
        'description' => '',
        'argv' => [
            'name' => 'bingcoolhuang'
        ],
        'fork_type' => CronForkProcess::FORK_TYPE_EXEC,
        'fork_success_callback' => function(ScheduleEvent $event) {
            var_dump($event->cron_name." fork success");
        }
    ]
];