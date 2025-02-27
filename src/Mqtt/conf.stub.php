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
    'exception_handler'        => \Swoolefy\Core\SwoolefyException::class,
    'master_process_name'      => 'php-swoolefy-mqtt-master',
    'manager_process_name'     => 'php-swoolefy-mqtt-manager',
    'worker_process_name'      => 'php-swoolefy-mqtt-worker',
    'www_user'                 => '',
    'host'                     => '0.0.0.0',
    'port'                     => '1883',
    'time_zone'                => 'PRC',
    'runtime_enable_coroutine' => true,

    //swoole setting
    'setting' => [
        'reactor_num'           => 1,
        'worker_num'            => 3,
        'max_request'           => 1000,
        'task_worker_num'       => 1,
        'task_tmpdir'           => '/dev/shm',
        'daemonize'             => 0,
        'dispatch_mode'         => 2,
        'open_mqtt_protocol'    => true, // only for mqtt
        'reload_async'          => true,
        'enable_deadlock_check' => false,
        'enable_coroutine'      => 1,
        'task_enable_coroutine' => 1,
        'log_file'               => \Swoolefy\Core\SystemEnv::loadLogFile('/tmp/' . APP_NAME . '/swoole.log'),
        'log_rotation'           => SWOOLE_LOG_ROTATION_DAILY,
        'pid_file'              => \Swoolefy\Core\SystemEnv::loadPidFile('/data/' . APP_NAME . '/log/server.pid'),

        'hook_flags'             => \Swoolefy\Core\SystemEnv::loadHookFlag(),
    ],

    'coroutine_setting' => [
        'max_coroutine' => 50000
    ],

    'enable_table_tick_task' => true,

    // 是否开启内存回收
    'gc_mem_cache_enable' => true,
    'gc_mem_cache_tick_time' => 10,

    'mqtt' => [
        'username'           => '',
        'password'           => '',
        'mqtt_event_handler' => \Swoolefy\Mqtt\MqttEventV3::class
    ],

    // 依赖于EnableSysCollector = true，否则设置没有意义,不生效
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