<?php

use Swoolefy\Core\SystemEnv;

return [
//    [
//        'cron_name' => "fixed:user:name",
//        'cron_expression' => '30', //每分钟执行一次
//        'exec_bin_file' => SystemEnv::PhpBinFile(),
//        'exec_script' => "script.php start test --c=fixed:user:name",
//        'argv' => [
//            'name' => 'bingcool',
//            'age' => 18,
//            'sex' => 'man',
//            'desc' => "fff kkkmm",
//            'daemon' => 1
//        ],
//        'extend' => [],
//        'description' => '',
//        'fork_type' => 'proc_open',
//    ],
    [
        'cron_name' => "shell",
        'cron_expression' => 10,
        'exec_bin_file' => "nohup /bin/bash",
        'exec_script' => APP_PATH.'/Python/shell.sh > /dev/null 2>&1 & echo $! > pidfile.pid',
        'with_block_lapping' => true,
        'argv' => [],
        'extend' => [],
        'description' => '',
        'fork_type' => 'proc_open',
    ]
];