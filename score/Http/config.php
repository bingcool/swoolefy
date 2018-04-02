<?php
// httpserver的配置
return [
	'application_index' => 'App\\Application',
	'start_init' => 'Swoolefy\\Core\\startInit',
	'master_process_name' => 'php-http-master',
	'manager_process_name' => 'php-http-manager',
	'worker_process_name' => 'php-http-worker',
	'www_user' => 'www',
	'host' => '0.0.0.0',
	'port' => '9502',
	'time_zone' => 'Asia/Shanghai',
	'swoole_version' => '1.9.15', //要求swoole的最低版本
	'swoole_process_mode' => 'nnn',//swoole的进程模式设置
	'gzip_level' => 2,
	'include_files' =>[],
	'setting' => [
		'reactor_num' => 1, //reactor thread num
		'worker_num' => 5,    //worker process num
		'max_request' => 1000,
		'task_worker_num' => 3,
		'task_tmpdir' => '/dev/shm',
		'daemonize' => 0,
		// http无状态，使用1或3
		'dispatch_mode' => 3,
		'reload_async' => true,
		'daemonize' => 0,
		'log_file' => __DIR__.'/log.txt',
		'pid_file' => __DIR__.'/server.pid',
	],

	// 是否内存化线上实时任务
	'table_tick_task' => true,
	// 设置内存表
	// 'table' => [
	// 	// 内存表名
	// 	'table_pv' => [
	// 		// 每个内存表建立的行数
	// 		'size' => 4,
	// 		// 字段
	// 		'fields'=> [
	// 			['count','int',8]
	// 		]
	// 	],
	// ]
	
];