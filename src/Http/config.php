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
include_once START_DIR_ROOT . '/' . APP_NAME . '/Config/defines.php';
// 加载应用层协议,根据项目实际路径加载
$app_config = include_once START_DIR_ROOT . '/' . APP_NAME . '/Config/config-' . SWOOLEFY_ENV . '.php';

// http配置项
return [
    // 应用层配置，需要根据实际项目导入
    'app_conf' => $app_config,
    'application_index' => '',
    'event_handler' => \Swoolefy\Core\EventHandler::class,
    'response_formatter' => \Swoolefy\Core\ResponseFormatter::class,
    'exception_handler' => '',
    'master_process_name' => 'php-swoolefy-http-master',
    'manager_process_name' => 'php-swoolefy-http-manager',
    'worker_process_name' => 'php-swoolefy-http-worker',
    'www_user' => 'www',
    'host' => '0.0.0.0',
    'port' => '9502',
    'time_zone' => 'PRC',
    'swoole_process_mode' => SWOOLE_PROCESS,
    'include_files' => [],
    'runtime_enable_coroutine' => true,
    'setting' => [
        'reactor_num' => 1,
        'worker_num' => 5,
        'max_request' => 1000,
        'task_worker_num' => 2,
        'task_tmpdir' => '/dev/shm',
        'daemonize' => 0,
        // http无状态，使用1或3
        'dispatch_mode' => 3,
        'reload_async' => true,
        'enable_coroutine' => 1,
        'task_enable_coroutine' => 1,

        // 压缩
        'http_compression' => true,
        // $level 压缩等级，范围是 1-9，等级越高压缩后的尺寸越小，但 CPU 消耗更多。默认为 1, 最高为 9
        'http_compression_level' => 1,

        'log_file' => '/tmp/' . APP_NAME . '/swoole_log.txt',
        'pid_file' => '/data/' . APP_NAME . '/log/server.pid',
    ],


    'coroutine_setting' => [
        'max_coroutine' => 50000
    ],

    // 内存表定义可以按照以下demo格式
    //'table' => [
    //    'table_process' => [
    //         内存表建立的行数,取决于建立的process进程数,最小值64
    //         'size' => 64,
    //          // 定义字段
    //          'fields'=> [
    //                /**
    //                 * 从4.3版本开始，底层对内存长度做了对齐处理.字符串长度必须为8的整数倍.
    //                 * 如长度为18会自动对齐到24字节
    //                 */
    //                 ['pid','int', 10],
    //                 ['process_name','string', 56],
    //              ]
    //           ]
    // ],

    // 是否内存化线上实时任务
    //'enable_table_tick_task' => true,

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