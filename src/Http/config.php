<?php
/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | @see https://github.com/bingcool/swoolefy
 * +----------------------------------------------------------------------
 */

$dc = \Swoolefy\Core\SystemEnv::loadDcEnv();

return [
    // 应用层配置
    'app_conf'                 => \Swoolefy\Core\SystemEnv::loadAppConf(),
    'application_bootstrap'    => '',
    'event_handler'            => \Swoolefy\Core\EventHandler::class,
    'response_formatter'       => \Swoolefy\Core\ResponseFormatter::class,
    'exception_handler'        => '',
    'master_process_name'      => 'php-swoolefy-http-master',
    'manager_process_name'     => 'php-swoolefy-http-manager',
    'worker_process_name'      => 'php-swoolefy-http-worker',
    'www_user'                 => '',
    'host'                     => '0.0.0.0',
    'port'                     => '9502',
    'time_zone'                => 'PRC',
    'swoole_process_mode'      => SWOOLE_PROCESS,
    'include_files'            => [],
    'runtime_enable_coroutine' => true,

    'setting' => [
        'reactor_num'            => 1,
        'worker_num'             => 5,
        'max_request'            => 1000,
        'task_worker_num'        => 2,
        'task_tmpdir'            => '/dev/shm',
        'task_enable_coroutine'  => 1,
        'task_max_request'       => 1000,
        'daemonize'              => 0,
        'dispatch_mode'          => 3,
        'reload_async'           => true,
        'enable_deadlock_check'  => false,
        'enable_coroutine'       => 1,
        'enable_preemptive_scheduler' => 1,
        // 压缩
        'http_compression'       => true,
        // $level 压缩等级，范围是 1-9，等级越高压缩后的尺寸越小，但 CPU 消耗更多。默认为 1, 最高为 9
        'http_compression_level' => 1,
        'log_file'               => \Swoolefy\Core\SystemEnv::loadLogFile('/tmp/' . APP_NAME . '/swoole_log.txt'),
        'pid_file'               => \Swoolefy\Core\SystemEnv::loadPidFile('/data/' . APP_NAME . '/log/server.pid'),

        // 静态处理
        'document_root' => START_DIR_ROOT.'/swaggerui',
        'enable_static_handler' => true,
        'http_autoindex' => true,
        'http_index_files' => ['index.html', 'index.txt'],
    ],


    'coroutine_setting' => [
        'max_coroutine' => 50000
    ],

    // 是否内存化线上实时任务
    'enable_table_tick_task' => true,

    // 内存表定义
    'table' => [
        'table_process' => [
             // 内存表建立的行数,取决于建立的process进程数,最小值64
            'size' => 64,
              // 定义字段
            'fields'=> [
                ['pid','int', 10],
                ['process_name','string', 56],
            ]
        ]
     ],

    // 依赖于enable_sys_collector = true，否则设置没有意义,不生效
    'enable_pv_collector'  => true,
    'enable_sys_collector' => true,
    'sys_collector_conf' => [
        'type'           => SWOOLEFY_SYS_COLLECTOR_UDP,
        'host'           => '127.0.0.1',
        'port'           => 9504,
        'from_service'   => 'http-app',
        'target_service' => 'collectorService/system',
        'event'          => 'collect',
        'tick_time'      => 2,
        'callback'       => function () {
            $sysCollector = new \Swoolefy\Core\SysCollector\SysCollector();
            return $sysCollector->test();
        }
    ],

    // 热更新
    'reload_conf'=>[
        'enable_reload'     => false,
        'after_seconds'     => 3,
        'monitor_path'      => APP_PATH, // 开发者自己定义目录
        'reload_file_types' => ['.php', '.html', '.js'],
        //'reloadFn'          => function () {}, // 定义此项，reload将被接管
        'ignore_dirs'       => [],
        'callback'          => function () {}
    ]
];