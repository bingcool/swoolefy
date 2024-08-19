<?php

use Swoolefy\Core\SystemEnv;

return [
    [
        'command' => "fixed:user:name",
        'cron_expression' => '20', //每分钟执行一次
        'desc' => '',
        'fork_type' => 'proc_open',
        'argv' => [
            'name' => 'bingcool',
            'age' => 18,
            'sex' => 'man',
            'desc' => "fff kkkmm",
            'daemon' => 1
        ],
        'exec_bin_file' => SystemEnv::PhpBinFile(),
        'exec_script' => "script.php start test --c=fixed:user:name --name=bingcool --age=18 --sex=man --desc='fff kkkmm' --daemon=1",
        'cron_name' => "fixed:user:name-20 --name=bingcool --age=18 --sex=man --desc='fff kkkmm' --daemon=1",
        'params' => []
    ],
//    [
//        'command' => "fixed:user:name",
//        'cron_expression' => '30', //每分钟执行一次
//        'desc' => '',
//        'fork_type' => 'proc_open',
//        'argv' => [
//            'name' => 'bingcool',
//            'age' => 18,
//            'sex' => 'man',
//            'desc' => "fff kkkmm",
//            'daemon' => 1
//        ],
//        'exec_bin_file' => SystemEnv::PhpBinFile(),
//        'exec_script' => "script.php start test --c=fixed:user:name --name=bingcool --age=18 --sex=man --desc='fff kkkmm' --daemon=1",
//        'cron_name' => "fixed:user:name-30 --name=bingcool --age=18 --sex=man --desc='fff kkkmm' --daemon=1",
//        'params' => []
//    ]
];