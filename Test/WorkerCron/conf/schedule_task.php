<?php

use Swoolefy\Core\SystemEnv;
use Swoolefy\Core\Schedule\ScheduleEvent;
use Swoolefy\Worker\Cron\CronForkProcess;

return [
    [
        'cron_name' => "shell1",
        'cron_expression' => 5,
        'exec_bin_file' => "/bin/bash",
        'exec_script' => APP_PATH.'/Python/shell.sh',
        'with_block_lapping' => true,
        'output' => '/dev/null',
        'extend' => [],
        'description' => '',
        'fork_type' => CronForkProcess::FORK_TYPE_PROC_OPEN,
        'success_callback' => function(\Swoolefy\Core\Schedule\ScheduleEvent $event){
            //var_dump($event->cron_name);
        },
    ],
    [
        'cron_name' => "shell2",
        'cron_expression' => 5,
        'exec_bin_file' => "/bin/bash",
        'exec_script' => APP_PATH.'/Python/shell1.sh',
        'with_block_lapping' => true,
        'output' => '/dev/null',
        'extend' => [],
        'description' => '',
        'fork_type' => CronForkProcess::FORK_TYPE_PROC_OPEN,
    ]
];