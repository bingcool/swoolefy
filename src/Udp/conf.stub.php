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
    'application_service'      => '',
    'event_handler'            => \Swoolefy\Core\EventHandler::class,
    'response_formatter'       => \Swoolefy\Core\ResponseFormatter::class,
    'exception_handler'        => '',
    'master_process_name'      => 'php-swoolefy-udp-master',
    'manager_process_name'     => 'php-swoolefy-udp-manager',
    'worker_process_name'      => 'php-swoolefy-udp-worker',
    'www_user'                 => '',
    'host'                     => '0.0.0.0',
    'port'                     => defined('WORKER_PORT') ? WORKER_PORT : 9505,
    'time_zone'                => 'PRC',
    'runtime_enable_coroutine' => true,

    // swoole setting (UDP)
    'setting' => [
        'reactor_num'           => 2,
        // UDP 无连接，worker_num 建议与 CPU 核数相当，按报文处理吞吐调整
        'worker_num'            => 4,
        'max_request'           => 20000,
        'task_worker_num'       => 1,
        'task_tmpdir'           => '/dev/shm',
        'task_enable_coroutine' => 1,
        'task_max_request'      => 1000,
        'daemonize'             => 0,
        'dispatch_mode'         => 3,
        'enable_coroutine'      => 1,
        'enable_preemptive_scheduler' => 1,
        'reload_async'          => true,
        'enable_deadlock_check' => false,
        // 在 PHP ZTS 下，如果使用 SWOOLE_PROCESS 模式，一定要设置该值为 true
        'single_thread'         => false,
        // 退出前最大等待时间
        'max_wait_time'         => 10,
        // UDP 接收/发送缓冲区，默认 2MB，可按峰值报文大小调整
        'socket_buffer_size'    => 2 * 1024 * 1024,
        // 单包最大长度；UDP 受内核限制通常不超过 64KB，大包请改用 TCP/HTTP
        'package_max_length'    => 65507,
        'log_file'              => \Swoolefy\Core\SystemEnv::loadLogFile('/tmp/' . APP_NAME . '/swoole.log'),
        'log_rotation'          => SWOOLE_LOG_ROTATION_DAILY,
        'display_errors'        => true,
        'pid_file'              => \Swoolefy\Core\SystemEnv::loadPidFile('/tmp/' . APP_NAME . '/server.pid'),
        'hook_flags'            => \Swoolefy\Core\SystemEnv::loadHookFlag(),
    ],

    'coroutine_setting' => [
        'max_coroutine' => 50000
    ],

    'enable_table_tick_task' => true,

    'gc_mem_cache_enable' => true,
    'gc_mem_cache_tick_time' => 10,

    'enable_pv_collector'  => false,
    'enable_sys_collector' => false,
    'sys_collector_conf' => [
        'type'           => SWOOLEFY_SYS_COLLECTOR_UDP,
        'host'           => '127.0.0.1',
        'port'           => 9504,
        'from_service'   => 'udp-app',
        'target_service' => 'collectorService/system',
        'event'          => 'collect',
        'tick_time'      => 2,
        'callback'       => function () {
            return [];
        }
    ],

    'reload_conf'=>[
        'enable_reload'     => false,
        'after_seconds'     => 3,
        'monitor_path'      => APP_PATH,
        'reload_file_types' => ['.php', '.html', '.js'],
        'ignore_dirs'       => [],
        'callback'          => function () {}
    ]
];
