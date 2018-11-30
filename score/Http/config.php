<?php
/**
+----------------------------------------------------------------------
| swoolefy framework bases on swoole extension development, we can use it easily!
+----------------------------------------------------------------------
| Licensed ( https://opensource.org/licenses/MIT )
+----------------------------------------------------------------------
| Author: bingcool <bingcoolhuang@gmail.com || 2437667702@qq.com>
+----------------------------------------------------------------------
*/

// http协议层配置
return [
	'application_index' => 'App\\Application',
	'start_init' => \Swoolefy\Core\StartInit::class,
	'master_process_name' => 'php-http-master',
	'manager_process_name' => 'php-http-manager',
	'worker_process_name' => 'php-http-worker',
	'www_user' => 'www',
	'host' => '0.0.0.0',
	'port' => '9502',
	'time_zone' => 'PRC',
	'swoole_version' => '1.9.17', //要求swoole的最低版本
	'swoole_process_mode' => SWOOLE_PROCESS,//swoole的进程模式设置
	'include_files' => [],
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
		'daemonize' => 0,

		'log_file' => __DIR__.'/log/log.txt',
		'pid_file' => __DIR__.'/log/server.pid',
	],

	// 是否内存化线上实时任务
	'open_table_tick_task' => true,
];