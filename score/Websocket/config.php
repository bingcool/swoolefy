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

// websocketserver协议层配置
return [
	'application_index' => 'App\\Application',
	'application_service' => 'Service\\Application',
	'start_init' => 'Swoolefy\\Core\\StartInit',
	'master_process_name' => 'php-websocket-master',
	'manager_process_name' => 'php-websocket-manager',
	'worker_process_name' => 'php-websocket-worker',
	'www_user' => 'www',
	'host' => '0.0.0.0',
	'port' => '9503',
	// websocket独有
	'accept_http' => false,
	'time_zone' => 'PRC', 
	'setting' => [
		'reactor_num' => 1,
		'worker_num' => 3,
		'max_request' => 1000,
		'task_worker_num' => 2,
		'task_tmpdir' => '/dev/shm',
		'daemonize' => 0,
		// websocket使用固定的worker，使用2或4
		'dispatch_mode' => 2,
		// 不要更改这个两个配置文件位置
		'log_file' => __DIR__.'/log.txt',
		'pid_file' => __DIR__.'/server.pid',
	],
	'open_table_tick_task' => true,
];