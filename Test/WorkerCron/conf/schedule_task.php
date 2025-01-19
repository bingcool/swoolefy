<?php

use Swoolefy\Core\SystemEnv;
use Swoolefy\Worker\Cron\CronForkProcess;

return [
//    [
//        'cron_name' => "fixed:user:name",
//        'cron_expression' => '30', //每分钟执行一次
//        'exec_bin_file' => SystemEnv::PhpBinFile(),
//        'exec_script' => "script.php start test --c=fixed:user:name",
//        'extend' => [],
//        'description' => '',
//        'fork_type' => 'proc_open',
//    ],
    [
        'cron_name' => "shell",
        'cron_expression' => 5,
        'exec_bin_file' => "/bin/bash",
        'exec_script' => APP_PATH.'/Python/shell.sh',
        'with_block_lapping' => true,
        'output' => '/dev/null',
        'extend' => [],
        'description' => '',
        'fork_type' => CronForkProcess::FORK_TYPE_PROC_OPEN,
    ]
];