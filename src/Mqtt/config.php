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

// 加载常量定义，根据项目实际路径加载
include START_DIR_ROOT . '/' . APP_NAME . '/Config/defines.php';
// 加载应用层协议,根据项目实际路径加载
$app_config = include START_DIR_ROOT . '/' . APP_NAME . '/Config/config-' . SWOOLEFY_ENV . '.php';

// mqttServer配置
return [
    // 应用层配置，需要根据实际项目导入
    'app_conf' => $app_config,
    'application_service' => '',
    'exception_handler' => \Swoolefy\Core\SwoolefyException::class,
    'master_process_name' => 'php-swoolefy-mqtt-master',
    'manager_process_name' => 'php-swoolefy-mqtt-manager',
    'worker_process_name' => 'php-swoolefy-mqtt-worker',
    'www_user' => 'www',
    'host' => '0.0.0.0',
    'port' => '1883',
    'time_zone' => 'PRC',
    'runtime_enable_coroutine' => true,
    'setting' => [
        'reactor_num' => 1,
        'worker_num' => 3,
        'max_request' => 1000,
        'task_worker_num' => 1,
        'task_tmpdir' => '/dev/shm',
        'daemonize' => 0,
        // TCP使用固定的worker，使用2或4或7
        'dispatch_mode' => 2,
        // mqtt
        'open_mqtt_protocol' => true,

        'enable_coroutine' => 1,
        'task_enable_coroutine' => 1,

        'log_file' => '/tmp/' . APP_NAME . '/swoole_log.txt',
        'pid_file' => '/data/' . APP_NAME . '/log/server.pid',

    ],

    'coroutine_setting' => [
        'max_coroutine' => 50000
    ],

    'mqtt' => [
        'username' => '',
        'password' => '',
        'protocol_level' => 4, // mqtt3.1.3
        // 需继承Swoolefy\Mqtt\MqttEvent
        'mqtt_event_handler' => \Swoolefy\Mqtt\MqttEvent::class

        //'protocol_level' => 5, // mqtt5
        // 需继承Swoolefy\Mqtt\MqttEvent5
        //'mqtt_event_handler' => \Swoolefy\Mqtt\MqttEvent5::class
    ],

    'enable_table_tick_task' => true,

    // 创建计算请求的原子计算实例,必须依赖于EnableSysCollector = true，否则设置没有意义,不生效
    //'enable_pv_collector' => true,
    // 信息采集器
    //'enable_sys_collector' => true,
    //    'sys_collector_conf' => [
    //    'type' => SWOOLEFY_SYS_COLLECTOR_UDP,
    //    'host' => '127.0.0.1',
    //    'port' => 9504,
    //    'from_service' => 'http-app',
    //    'target_service' => 'collectorService/system',
    //    'event' => 'collect',
    //    'tick_time' => 2,
    //    'callback' => function() {
    //          // todo,在这里实现获取需要收集的信息并返回
    //          $sysCollector = new \Swoolefy\Core\SysCollector\SysCollector();
    //          return $sysCollector->test();
    //     }
    //],

    // 热更新
    //'reload_conf'=>[
    //    'enable_reload' => true,
    //    'after_seconds' => 3,
    //    'monitor_path' => APP_PATH,//开发者自己定义目录
    //    'reload_file_types' => ['.php','.html','.js'],
    //    'ignore_dirs' => [],
    //    'callback' => function() {
    //        var_dump("callback");
    //    }
    //],
];