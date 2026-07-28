<?php

/**
 * MQTT broker configuration stub (production defaults).
 */

$dc = \Swoolefy\Core\SystemEnv::loadDcEnv();

return [
    'app_conf'                 => \Swoolefy\Core\SystemEnv::loadAppConf(),
    'application_service'      => '',
    'event_handler'            => \Swoolefy\Core\EventHandler::class,
    'exception_handler'        => \Swoolefy\Core\SwoolefyException::class,
    'master_process_name'      => 'php-swoolefy-mqtt-master',
    'manager_process_name'     => 'php-swoolefy-mqtt-manager',
    'worker_process_name'      => 'php-swoolefy-mqtt-worker',
    'www_user'                 => '',
    'host'                     => '0.0.0.0',
    'port'                     => defined('WORKER_PORT') ? WORKER_PORT : 1883,
    'time_zone'                => 'PRC',
    'runtime_enable_coroutine' => true,

    'setting' => [
        'reactor_num'           => swoole_cpu_num(),
        'worker_num'            => swoole_cpu_num(),
        'max_request'           => 10000,
        'task_worker_num'       => swoole_cpu_num(),
        'task_tmpdir'           => '/dev/shm',
        'daemonize'             => 0,
        'dispatch_mode'         => 2,
        'open_mqtt_protocol'    => true,
        'package_max_length'    => 2 * 1024 * 1024,
        'heartbeat_check_interval' => 60,
        'heartbeat_idle_time'    => 120,
        // Worker 退出前等待在途请求（优雅停机配合 graceful_shutdown.drain_timeout）
        'max_wait_time'         => 10,
        'reload_async'          => true,
        'enable_deadlock_check' => false,
        'enable_coroutine'      => 1,
        'task_enable_coroutine' => 1,
        'log_file'              => \Swoolefy\Core\SystemEnv::loadLogFile('/tmp/' . APP_NAME . '/swoole.log'),
        'log_rotation'          => SWOOLE_LOG_ROTATION_DAILY,
        'pid_file'              => \Swoolefy\Core\SystemEnv::loadPidFile('/data/' . APP_NAME . '/log/server.pid'),
        'hook_flags'            => \Swoolefy\Core\SystemEnv::loadHookFlag(),
    ],

    /*
     * 优雅停机：停接新会话 → 排空在途 PUBLISH/QoS → 再断连
     *
     * drain_timeout 建议 ≥ setting.max_wait_time；
     * StopCmd 会按 drain_timeout + max_wait_time 拉长强制 kill 等待。
     */
    'graceful_shutdown' => [
        'enable' => true,
        'drain_timeout' => 30,
        'reject_reason' => 'server shutting down',
    ],

    'coroutine_setting' => [
        'max_coroutine' => 50000,
    ],

    'enable_table_tick_task' => true,
    'gc_mem_cache_enable'    => true,
    'gc_mem_cache_tick_time' => 10,

    /**
     * MQTT Broker 配置
     *
     * protocol_level   : 4 = MQTT 3.1.1，5 = MQTT 5.0
     * auto_protocol    : true 时从 CONNECT 报文自动识别协议版本
     * username/password: 均为空时不鉴权；生产环境务必设置
     * mqtt_event_handler: 事件类，默认 ProductionMqttEventV3
     */
    'mqtt' => [
        'protocol_level'     => MQTT_PROTOCOL_LEVEL3,
        'auto_protocol'      => false,
        'username'           => '',
        'password'           => '',
        'mqtt_event_handler' => \Swoolefy\Mqtt\ProductionMqttEventV3::class,
        // Retain 内存上限（防不可信客户端刷爆 Worker）
        'retain_limits' => [
            'max_topics' => 10000,
            'max_message_bytes' => 262144,
            'max_total_bytes' => 16777216,
        ],
    ],

    'enable_pv_collector'  => true,
    'enable_sys_collector' => true,
    'sys_collector_conf'   => [
        'type'           => SWOOLEFY_SYS_COLLECTOR_UDP,
        'host'           => '127.0.0.1',
        'port'           => 9504,
        'from_service'   => 'mqtt-app',
        'target_service' => 'collectorService/system',
        'event'          => 'collect',
        'tick_time'      => 2,
        'callback'       => static function () {
            $sysCollector = new \Swoolefy\Core\SysCollector\SysCollector();

            return $sysCollector->test();
        },
    ],

    'reload_conf' => [
        'enable_reload'     => false,
        'after_seconds'     => 3,
        'monitor_path'      => APP_PATH,
        'reload_file_types' => ['.php', '.html', '.js'],
        'ignore_dirs'       => [],
        'callback'          => static function () {},
    ],
];
